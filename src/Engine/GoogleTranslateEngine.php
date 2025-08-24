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

final class GoogleTranslateEngine implements TranslatorEngineInterface
{
    public function __construct(
        private readonly HttpClientInterface $client, // base_uri 'https://translation.googleapis.com'
        private readonly string $name,
        private readonly ?string $apiKey = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $defaultTtl = 0,
        private readonly string $baseUriForKey = '',
    ) {}

    public function getName(): string { return $this->name; }

    public function capabilities(): EngineCapabilities
    {
        return new EngineCapabilities(supportsGlossary: false, supportsHtml: true);
    }

    public function translate(TranslationRequest $req): TranslationResult
    {
        $payload = [
            'q'      => $req->text,
            'source' => $req->source,
            'target' => $req->target,
            'format' => $req->html ? 'html' : 'text',
        ] + $req->extra;

        $key = $this->cacheKey('translate', $payload);

        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/language/translate/v2', [
                'query' => $this->apiKey ? ['key' => $this->apiKey] : [],
                'json'  => $payload,
            ]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{data?: array{translations?: array<int, array{translatedText:string, detectedSourceLanguage?:string}>}} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

        $first = $data['data']['translations'][0] ?? ['translatedText' => '', 'detectedSourceLanguage' => $req->source];

        return new TranslationResult(
            translatedText: (string)($first['translatedText'] ?? ''),
            detectedSource: (string)($first['detectedSourceLanguage'] ?? ($req->source === 'auto' ? 'auto' : $req->source)),
            meta: ['engine' => $this->name]
        );
    }

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult
    {
        $payload = [
            'q'      => array_values($req->texts),
            'source' => $req->source,
            'target' => $req->target,
            'format' => $req->html ? 'html' : 'text',
        ] + $req->extra;

        $key = $this->cacheKey('translateBatch', $payload);

        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/language/translate/v2', [
                'query' => $this->apiKey ? ['key' => $this->apiKey] : [],
                'json'  => $payload,
            ]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{data?: array{translations?: array<int, array{translatedText:string, detectedSourceLanguage?:string}>}} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

        $translations = $data['data']['translations'] ?? [];
        $texts = [];
        $detected = $req->source === 'auto' ? ($translations[0]['detectedSourceLanguage'] ?? 'auto') : $req->source;
        foreach ($translations as $tr) {
            $texts[] = (string)($tr['translatedText'] ?? '');
        }

        return new TranslationBatchResult(
            translatedTexts: $texts,
            detectedSource: (string)$detected,
            meta: ['engine' => $this->name]
        );
    }

    public function detect(string $text): LanguageDetectionResult
    {
        $payload = ['q' => $text];
        $key = $this->cacheKey('detect', $payload);

        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/language/translate/v2/detect', [
                'query' => $this->apiKey ? ['key' => $this->apiKey] : [],
                'json'  => $payload,
            ]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{data?: array{detections?: array<int, array<int, array{language:string, confidence:float}>>>}} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

        $first = $data['data']['detections'][0][0] ?? ['language' => 'und', 'confidence' => 0.0];

        return new LanguageDetectionResult(
            language: (string)($first['language'] ?? 'und'),
            confidence: (float)($first['confidence'] ?? 0.0),
            meta: ['engine' => $this->name]
        );
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
        return "survos.translator.google.$op.$hash";
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
