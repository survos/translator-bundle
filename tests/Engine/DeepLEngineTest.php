<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\TranslatorBundle\Engine\DeepLEngine;
use Survos\TranslatorBundle\Exception\EngineHttpException;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeepLEngineTest extends TestCase
{
    #[Test]
    public function translateThrottlesBetweenLiveRequests(): void
    {
        $times = [];
        $mock = new MockHttpClient(function () use (&$times): MockResponse {
            $times[] = microtime(true);

            return new MockResponse(json_encode(['translations' => [['text' => 'ok']]]));
        });

        $engine = new DeepLEngine($mock, 'deepl-test');
        $engine->translate(new TranslationRequest(text: 'first', source: 'hu', target: 'en'));
        $engine->translate(new TranslationRequest(text: 'second', source: 'hu', target: 'en'));

        self::assertCount(2, $times);
        // usleep() isn't microsecond-exact — allow a hair of scheduling slack rather than demand
        // precisely >= 0.5.
        self::assertGreaterThanOrEqual(0.49, $times[1] - $times[0], 'the second live request must wait out MIN_REQUEST_INTERVAL');
    }

    #[Test]
    public function translateRetriesOnceAfter429ThenSucceeds(): void
    {
        $attempt = 0;
        $mock = new MockHttpClient(function () use (&$attempt): MockResponse {
            $attempt++;
            if ($attempt === 1) {
                return new MockResponse('rate limited', ['http_code' => 429, 'response_headers' => ['retry-after' => '1']]);
            }

            return new MockResponse(json_encode(['translations' => [['text' => 'Cat']]]));
        });

        $engine = new DeepLEngine($mock, 'deepl-test');
        $start = microtime(true);
        $result = $engine->translate(new TranslationRequest(text: 'macska', source: 'hu', target: 'en'));
        $elapsed = microtime(true) - $start;

        self::assertSame(2, $attempt, 'must retry exactly once after a 429');
        self::assertSame('Cat', $result->translatedText);
        self::assertGreaterThanOrEqual(1.0, $elapsed, 'must actually wait out Retry-After before retrying');
    }

    #[Test]
    public function translateThrowsWithRetryAfterWhenTheRetryAlsoFails(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse(
            'rate limited',
            ['http_code' => 429, 'response_headers' => ['retry-after' => '1']],
        ));

        $engine = new DeepLEngine($mock, 'deepl-test');

        try {
            $engine->translate(new TranslationRequest(text: 'macska', source: 'hu', target: 'en'));
            self::fail('expected EngineHttpException');
        } catch (EngineHttpException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertSame(1, $e->retryAfter);
        }
    }

    #[Test]
    public function translateBatchStillSendsOneRequestForAllTexts(): void
    {
        $requestCount = 0;
        $capturedBody = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestCount, &$capturedBody): MockResponse {
            $requestCount++;
            // Symfony HttpClient urlencodes an array 'body' into a query-string-formatted string
            // before MockHttpClient's callback sees it — parse it back to inspect what was sent.
            parse_str((string) $options['body'], $capturedBody);

            return new MockResponse(json_encode([
                'translations' => [['text' => 'Cat'], ['text' => 'Dog']],
            ]));
        });

        $engine = new DeepLEngine($mock, 'deepl-test');
        $result = $engine->translateBatch(new \Survos\TranslatorBundle\Model\TranslationBatchRequest(
            texts: ['macska', 'kutya'],
            source: 'hu',
            target: 'en',
        ));

        self::assertSame(1, $requestCount, 'a batch of texts must still be a single HTTP request, not one per text');
        self::assertSame(['macska', 'kutya'], $capturedBody['text']);
        self::assertSame(['Cat', 'Dog'], $result->translatedTexts);
    }
}
