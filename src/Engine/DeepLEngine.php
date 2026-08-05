<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Engine;

use Psr\Cache\CacheItemPoolInterface;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Exception\EngineHttpException;
use Survos\TranslatorBundle\Model\{
    EngineCapabilities,
    LanguageDetectionResult,
    TranslationBatchRequest,
    TranslationBatchResult,
    TranslationRequest,
    TranslationResult
};
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DeepLEngine implements TranslatorEngineInterface
{
    /**
     * Minimum seconds between live (uncached, non-429) requests, self-imposed regardless of
     * what DeepL's response says — free-tier rate limits are tight enough that firing requests
     * back-to-back (previously ~120ms apart, ~8/sec) 429s almost immediately. Static so it holds
     * across DeepLEngine instances within one worker process, not just one instance's lifetime.
     */
    private const MIN_REQUEST_INTERVAL = 0.5;

    /** Hard cap so a single message handling can't block a worker indefinitely on repeated 429s. */
    private const MAX_RETRY_WAIT = 60;

    private static ?float $lastRequestAt = null;

    public function __construct(
        private readonly HttpClientInterface $client, // scoped client with base_uri like https://api-free.deepl.com
        private readonly string $name,                // engine config key
        private readonly ?string $apiKey = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $defaultTtl = 0,
        private readonly string $baseUriForKey = '',
    ) {}

    public function getName(): string { return $this->name; }

    public function capabilities(): EngineCapabilities
    {
        // DeepL supports glossaries; we pass through glossaryId if present.
        return new EngineCapabilities(supportsGlossary: true, supportsHtml: true);
    }

    public function translate(TranslationRequest $req): TranslationResult
    {
        $body = [
            'target_lang' => strtoupper($req->target),
            'text'        => $req->text, // FIX: send a single 'text' field (not an array)
        ];
        if ($req->source !== '' && strtolower($req->source) !== 'auto') {
            $body['source_lang'] = strtoupper($req->source);
        }
        if ($req->html) {
            $body['tag_handling'] = 'html';
        }
        if ($req->glossaryId) {
            $body['glossary_id'] = $req->glossaryId;
        }
        foreach ($req->extra as $k => $v) {
            $body[(string)$k] = $v;
        }

        $key = $this->cacheKey('translate', $body);

        $data = $this->cachedArray($key, fn (): array => $this->request($body));

        $first = $data['translations'][0] ?? ['text' => '', 'detected_source_language' => $req->source];

        return new TranslationResult(
            translatedText: (string)($first['text'] ?? ''),
            detectedSource: (string)($first['detected_source_language'] ?? ($req->source === 'auto' ? 'auto' : $req->source)),
            meta: ['engine' => $this->name]
        );
    }

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult
    {
        $body = [
            'target_lang' => strtoupper($req->target),
            'text'        => array_values($req->texts),
        ];
        if ($req->source !== '' && strtolower($req->source) !== 'auto') {
            $body['source_lang'] = strtoupper($req->source);
        }
        if ($req->html) {
            $body['tag_handling'] = 'html';
        }
        foreach ($req->extra as $k => $v) {
            $body[(string)$k] = $v;
        }

        $key = $this->cacheKey('translateBatch', $body);

        $data = $this->cachedArray($key, fn (): array => $this->request($body));

        $translations = $data['translations'] ?? [];
        $texts = [];
        $detected = $req->source === 'auto' ? ($translations[0]['detected_source_language'] ?? 'auto') : $req->source;
        foreach ($translations as $tr) {
            $texts[] = (string)($tr['text'] ?? '');
        }

        return new TranslationBatchResult(
            translatedTexts: $texts,
            detectedSource: (string)$detected,
            meta: ['engine' => $this->name]
        );
    }

    public function detect(string $text): LanguageDetectionResult
    {
        // DeepL has no separate detect endpoint; translate to EN (cheap) and read detected_source_language.
        $payload = [
            'text'        => [$text],
            'target_lang' => 'EN',
        ];
        $key = $this->cacheKey('detect', $payload);

        $data = $this->cachedArray($key, fn (): array => $this->request($payload));

        $first = $data['translations'][0] ?? [];
        $lang = (string)($first['detected_source_language'] ?? 'und');

        return new LanguageDetectionResult(
            language: $lang,
            confidence: 0.0, // DeepL does not expose confidence
            meta: ['engine' => $this->name]
        );
    }

    private function authHeaders(): array
    {
        return $this->apiKey ? ['Authorization' => 'DeepL-Auth-Key ' . $this->apiKey] : [];
    }

    /**
     * Shared POST /v2/translate call for translate()/translateBatch()/detect() — previously each
     * had its own copy of this with no throttling and no Retry-After handling, which is how a
     * one-request-per-phrase caller (Messenger dispatching one TransitionMessage per translation)
     * turned into a sustained ~8 req/sec hammering of DeepL's free-tier rate limit: every request
     * 429'd instantly, Messenger retried in ~2s per its own generic backoff (far too short for a
     * rate-limit cooldown), and it 429'd again forever.
     *
     * Two independent mitigations, not a fix for the underlying one-request-per-phrase dispatch
     * pattern (that lives outside this bundle, in whatever dispatches TransitionMessage):
     *   - self-throttle to MIN_REQUEST_INTERVAL between requests, regardless of outcome;
     *   - on 429, sleep the origin's own Retry-After (capped at MAX_RETRY_WAIT) and retry ONCE
     *     before giving up — DeepL's free tier sends a real Retry-After header, so this is usually
     *     enough for a single phrase's cooldown, without blocking a worker indefinitely.
     *
     * @param array<mixed> $body
     * @return array<mixed>
     */
    private function request(array $body, bool $isRetry = false): array
    {
        $this->throttle();

        $resp = $this->client->request('POST', '/v2/translate', [
            'headers' => $this->authHeaders(),
            'body'    => $body,
        ]);
        $status = $resp->getStatusCode();

        if ($status === 429 && !$isRetry) {
            $retryAfter = min(self::MAX_RETRY_WAIT, $this->parseRetryAfter($resp->getHeaders(false)));
            sleep($retryAfter);

            return $this->request($body, isRetry: true);
        }

        if ($status >= 400) {
            $retryAfter = $status === 429 ? $this->parseRetryAfter($resp->getHeaders(false)) : null;
            throw new EngineHttpException($status, $resp->getContent(false), $retryAfter);
        }

        /** @var array{translations?: array<int, array{text:string, detected_source_language?:string}>} $arr */
        return $resp->toArray();
    }

    private function throttle(): void
    {
        if (null !== self::$lastRequestAt) {
            $wait = self::MIN_REQUEST_INTERVAL - (microtime(true) - self::$lastRequestAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        self::$lastRequestAt = microtime(true);
    }

    /** @param array<string, list<string>> $headers */
    private function parseRetryAfter(array $headers): int
    {
        $value = $headers['retry-after'][0] ?? null;
        if (null === $value || !is_numeric($value)) {
            return self::MAX_RETRY_WAIT;
        }

        return max(1, (int) $value);
    }

    /** @param array<mixed> $payload */
    private function cacheKey(string $op, array $payload): string
    {
        $hash = hash('xxh3', json_encode([
            'op'      => $op,
            'name'    => $this->name,
            'base'    => $this->baseUriForKey,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
        return "survos.translator.deepl.$op.$hash";
    }

    /**
     * @param callable():array $producer
     * @return array<mixed>
     */
    private function cachedArray(string $key, callable $producer): array
    {
        if (!$this->cache instanceof CacheItemPoolInterface) {
            return $producer();
        }
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $val = $item->get();
            if (\is_array($val)) {
                return $val;
            }
        }
        $data = $producer();
        $item->set($data);
        if ($this->defaultTtl > 0) {
            $item->expiresAfter($this->defaultTtl);
        }
        $this->cache->save($item);
        return $data;
    }
}
