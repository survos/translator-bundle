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

final readonly class LibreTranslateEngine implements TranslatorEngineInterface
{
    public function __construct(
        private HttpClientInterface     $client, // scoped client
        private string                  $name,                // engine config key
        private ?string                 $apiKey = null,
        private ?CacheItemPoolInterface $cache = null,
        private int                     $defaultTtl = 0,         // seconds; 0 => no expiration
        private string                  $baseUriForKey = '',  // avoid key collisions across hosts
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
            'alternatives' => 3,
            'format' => $req->html ? 'html' : 'text',
        ] + ($this->apiKey ? ['api_key' => $this->apiKey] : []) + $req->extra;

        $key  = $this->cacheKey('translate', $payload);
        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/translate', ['json' => $payload]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{translatedText?:string, detectedLanguage?:string} $arr */
            $arr = $resp->toArray();
            return $arr;
        });
        dump($data);
        $translatedText = $data['translatedText'] ?? '';
        if ($translatedText === $req->text) {
            if (count($data['alternatives']??[])) {
                $translatedText = $data['alternatives'][0];
                // hack to fix this:
                /*
                {
    "alternatives": [
        "c) calendario",
        "c) hora",
        "c) plantilla"
    ],
    "detectedLanguage": {
        "confidence": 90,
        "language": "en"
    },
    "translatedText": "timeline"
}
                */
                $translatedText = trim(preg_replace('/.*?\)/', '', $translatedText));
            }
        }

        return new TranslationResult(
            translatedText: $translatedText,
            detectedSource: $data['detectedLanguage']['language'] ?? ($req->source === 'auto' ? 'auto' : $req->source),
            meta: ['engine' => $this->name]
        );
    }

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult
    {
        $payload = [
            'q'      => $req->texts,
            'source' => $req->source,
            'target' => $req->target,
            'format' => $req->html ? 'html' : 'text',
        ] + ($this->apiKey ? ['api_key' => $this->apiKey] : []) + $req->extra;

        $key  = $this->cacheKey('translateBatch', $payload);
        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/translate', ['json' => $payload]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array{translatedText?:array<int,string>, detectedLanguage?:string} $arr */
            $arr = $resp->toArray();
            return $arr;
        });

        return new TranslationBatchResult(
            translatedTexts: $data['translatedText'] ?? [],
            detectedSource: $data['detectedLanguage'] ?? ($req->source === 'auto' ? 'auto' : $req->source),
            meta: ['engine' => $this->name]
        );
    }

    public function detect(string $text): LanguageDetectionResult
    {
        $payload = ['q' => $text] + ($this->apiKey ? ['api_key' => $this->apiKey] : []);
        $key  = $this->cacheKey('detect', $payload);
        $data = $this->cachedArray($key, function () use ($payload): array {
            $resp = $this->client->request('POST', '/detect', ['json' => $payload]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                throw new EngineHttpException($status, $resp->getContent(false));
            }
            /** @var array<array{language:string, confidence:float}> $arr */
            $arr = $resp->toArray();
            return $arr;
        });

        $best = $data[0] ?? ['language' => 'und', 'confidence' => 0.0];

        return new LanguageDetectionResult(
            language: (string)($best['language'] ?? 'und'),
            confidence: (float)($best['confidence'] ?? 0.0),
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
        return "survos.translator.$op.$hash";
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
