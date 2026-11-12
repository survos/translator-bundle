<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Survos\Bergamot\BergamotException;
use Survos\Bergamot\Translation;
use Survos\Bergamot\TranslatorInterface;
use Survos\TranslatorBundle\Engine\InProcessEngine;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Exception\TranslatorException;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gate: translate in this process when the models are here and cover the pair, otherwise hand over to the
 * fallback. The translator is a stand-in that upper-cases; survos/bergamot has the tests with real models.
 */
final class InProcessEngineTest extends TestCase
{
    /** @var list<string> */
    private array $httpRequests = [];

    public function testAPairTheModelsCoverIsTranslatedHereAndTheFallbackIsNotAsked(): void
    {
        $engine = $this->engine($model = new UppercaseTranslator());

        $result = $engine->translate(new TranslationRequest('guten morgen.', 'de', 'en'));

        self::assertSame('GUTEN MORGEN.', $result->translatedText);
        self::assertSame(['guten morgen.'], $model->seen);
        self::assertSame([], $this->httpRequests);
        self::assertTrue($result->meta['in_process']);
        self::assertSame('native', $result->meta['via']);
        self::assertSame(['test/de-en'], $result->meta['models']);
    }

    public function testAPairTheModelsDoNotCoverGoesToTheFallback(): void
    {
        $engine = $this->engine($model = new UppercaseTranslator());

        $result = $engine->translate(new TranslationRequest('Good morning.', 'en', 'hu'));

        self::assertSame('from-http', $result->translatedText);
        self::assertSame([], $model->seen);
        self::assertSame(['/translate'], $this->httpRequests);
        self::assertFalse($result->meta['in_process']);
        self::assertSame('libre', $result->meta['via']);
        self::assertSame('cannot write "hu"', $result->meta['in_process_skipped']);
    }

    public function testAMachineWithoutTheModelsUsesTheFallbackForEverything(): void
    {
        $engine = $this->engine(new UppercaseTranslator(unavailable: 'model test/de-en is not installed (44 MB)'));

        $result = $engine->translate(new TranslationRequest('guten morgen.', 'de', 'en'));

        self::assertSame('from-http', $result->translatedText);
        self::assertSame('model test/de-en is not installed (44 MB)', $result->meta['in_process_skipped']);
        self::assertFalse($engine->handlesInProcess('de', 'en'));
    }

    public function testATextTheModelRefusesGoesToTheFallback(): void
    {
        $engine = $this->engine(new UppercaseTranslator(refuses: 'A sentence exceeds 120 model tokens'));

        $result = $engine->translate(new TranslationRequest('ein sehr langer satz.', 'de', 'en'));

        self::assertSame('from-http', $result->translatedText);
        self::assertSame('A sentence exceeds 120 model tokens', $result->meta['in_process_skipped']);
    }

    public function testWithoutAFallbackTheReasonIsTheError(): void
    {
        $engine = new InProcessEngine(new UppercaseTranslator(), 'native');

        $this->expectException(TranslatorException::class);
        $this->expectExceptionMessage('native: cannot write "hu"');
        $engine->translate(new TranslationRequest('Good morning.', 'en', 'hu'));
    }

    public function testABatchStaysHereAndAnUncoveredBatchGoesToTheFallbackInOneRequest(): void
    {
        $engine = $this->engine(new UppercaseTranslator());

        $here = $engine->translateBatch(new TranslationBatchRequest(['eins.', 'zwei.'], 'de', 'en'));
        self::assertSame(['EINS.', 'ZWEI.'], $here->translatedTexts);
        self::assertSame(2, $here->meta['in_process_texts']);
        self::assertSame(0, $here->meta['fallback_texts']);
        self::assertSame([], $this->httpRequests);

        $there = $engine->translateBatch(new TranslationBatchRequest(['one.', 'two.', 'three.'], 'en', 'hu'));
        self::assertSame(['from-http-1', 'from-http-2', 'from-http-3'], $there->translatedTexts);
        self::assertSame(['/translate'], $this->httpRequests, 'one request for the whole batch');
        self::assertSame('libre', $there->meta['via']);
    }

    public function testCapabilitiesSayWhatItCoversAndWhoStandsBehindIt(): void
    {
        $meta = $this->engine(new UppercaseTranslator())->capabilities()->meta;

        self::assertSame(['en', 'de'], $meta['languages']);
        self::assertSame('libre', $meta['fallback']);
    }

    private function engine(TranslatorInterface $translator): InProcessEngine
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->httpRequests[] = (string) parse_url($url, \PHP_URL_PATH);
            $q = json_decode((string) $options['body'], true)['q'] ?? '';
            $text = \is_array($q) ? array_map(static fn (int $i): string => 'from-http-'.($i + 1), array_keys($q)) : 'from-http';

            return new MockResponse((string) json_encode(['translatedText' => $text]));
        }, 'https://libre.test');

        return new InProcessEngine($translator, 'native', new LibreTranslateEngine($http, 'libre'));
    }
}

/** Stands in for survos/bergamot's Translator: knows German and English, upper-cases, remembers what it was given. */
final class UppercaseTranslator implements TranslatorInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(private readonly ?string $unavailable = null, private readonly ?string $refuses = null)
    {
    }

    public function languages(): array
    {
        return ['en', 'de'];
    }

    public function whyNot(string $source, string $target): ?string
    {
        if (!\in_array($target, ['en', 'de'], true)) {
            return sprintf('cannot write "%s"', $target);
        }

        return $this->unavailable;
    }

    public function translate(string $text, string $source, string $target): Translation
    {
        if (null !== $this->refuses) {
            throw new BergamotException($this->refuses);
        }
        $this->seen[] = $text;

        return new Translation(mb_strtoupper($text), ["test/$source-$target"], 1, 5, 0.1, 1);
    }
}
