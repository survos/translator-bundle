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
            // DeepL expects uppercase language codes like EN, ES, PT-BR
            'target_lang' => strtoupper($req->target),
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
        // Allow additional DeepL params via extra (e.g., formality)
        foreach ($req->extra as $k => $v) {
            $body[(string)$k] = $v;
        }
        $body['text'] = [$req->text];

        $key = $this->cacheKey('translate', $body);

        $data = $this->cachedArray($key, function () use ($body): array {
            $resp = $this->client->request('POST', '/v2/translate', [
                'headers' => $this->authHeaders(),
                'body'    => $body, // x-www-form-urlencoded
            ]);
            dd($this->apiKey, $this->authHeaders());
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{translations?: array<int, array{text:string, detected_source_language?:string}>} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

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

        $data = $this->cachedArray($key, function () use ($body): array {
            $resp = $this->client->request('POST', '/v2/translate', [
                'headers' => $this->authHeaders(),
                'body'    => $body,
            ]);
            dd($body, $this->authHeaders());
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{translations?: array<int, array{text:string, detected_source_language?:string}>} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

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

        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/v2/translate', [
                'headers' => $this->authHeaders(),
                'body'    => $payload,
            ]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{translations?: array<int, array{text:string, detected_source_language?:string}>} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

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
