<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LibreTranslateProvenanceTest extends TestCase
{
    public function testModelProvenanceSurvivesBothResponseShapesWithoutChangingProfileName(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['translatedText' => 'Image', 'engine' => 'backend', 'model' => 'test/model', 'revision' => 'abc', 'secret' => 'hidden'])),
            new MockResponse(json_encode(['translatedText' => ['Image'], 'model' => 'test/model', 'revision' => 'abc'])),
        ], 'https://example.test');
        $engine = new LibreTranslateEngine($http, 'my-model-v1');
        foreach ([$engine->translate(new TranslationRequest('Bild', 'de', 'en')), $engine->translateBatch(new TranslationBatchRequest(['Bild'], 'de', 'en'))] as $result) {
            self::assertSame(['engine' => 'my-model-v1', 'model' => 'test/model', 'revision' => 'abc'], $result->meta);
        }
    }
}
