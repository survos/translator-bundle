<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Engine;

use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Model\{
    TranslationRequest, TranslationResult,
    TranslationBatchRequest, TranslationBatchResult,
    LanguageDetectionResult, EngineCapabilities
};
use Survos\TranslatorBundle\Exception\EngineHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LibreTranslateEngine implements TranslatorEngineInterface
{
    public function __construct(
        private readonly HttpClientInterface $client, // can be a scoped client
        private readonly string $name,                // engine name (config key)
        private readonly ?string $apiKey = null,
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
        ] + ($this->apiKey ? ['api_key' => $this->apiKey] : []) + $req->extra;

        $resp = $this->client->request('POST', '/translate', ['json' => $payload]);
        $status = $resp->getStatusCode();
        if ($status >= 400) throw new EngineHttpException($status, $resp->getContent(false));

        /** @var array{translatedText?:string, detectedLanguage?:string} $data */
        $data = $resp->toArray();

        return new TranslationResult(
            translatedText: $data['translatedText'] ?? '',
            detectedSource: $data['detectedLanguage'] ?? ($req->source === 'auto' ? 'auto' : $req->source),
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

        $resp = $this->client->request('POST', '/translate', ['json' => $payload]);
        $status = $resp->getStatusCode();
        if ($status >= 400) throw new EngineHttpException($status, $resp->getContent(false));

        /** @var array{translatedText?:array<int,string>, detectedLanguage?:string} $data */
        $data = $resp->toArray();

        return new TranslationBatchResult(
            translatedTexts: $data['translatedText'] ?? [],
            detectedSource: $data['detectedLanguage'] ?? ($req->source === 'auto' ? 'auto' : $req->source),
            meta: ['engine' => $this->name]
        );
    }

    public function detect(string $text): LanguageDetectionResult
    {
        $payload = ['q' => $text] + ($this->apiKey ? ['api_key' => $this->apiKey] : []);
        $resp = $this->client->request('POST', '/detect', ['json' => $payload]);
        $status = $resp->getStatusCode();
        if ($status >= 400) throw new EngineHttpException($status, $resp->getContent(false));

        /** @var array<array{language:string, confidence:float}> $data */
        $data = $resp->toArray();
        $best = $data[0] ?? ['language' => 'und', 'confidence' => 0.0];

        return new LanguageDetectionResult(
            language: $best['language'],
            confidence: (float) $best['confidence'],
            meta: ['engine' => $this->name]
        );
    }
}
