<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Engine;

use Survos\Bergamot\BergamotException;
use Survos\Bergamot\TranslatorInterface;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Exception\TranslatorException;
use Survos\TranslatorBundle\Model\EngineCapabilities;
use Survos\TranslatorBundle\Model\LanguageDetectionResult;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationBatchResult;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Model\TranslationResult;

/**
 * Translates inside this PHP process when it can, and hands the request to another engine when it cannot.
 *
 * The translating is survos/bergamot's (models through FFI, sentence splitting, pivoting through English). This class
 * is the gate in front of it: "can" means the models are installed on this machine and cover the language pair.
 * Everything else goes to the fallback engine, typically the HTTP engine this one stands in front of: a pair the
 * models do not cover, a machine without them, a text they refuse. Without a fallback those cases are errors that say
 * why.
 *
 * Nothing is cached here. A call costs milliseconds, and the application's translation memory is the one place a
 * finished translation is kept. `meta.via` on every result says who translated.
 */
final class InProcessEngine implements TranslatorEngineInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly string $name,
        private readonly ?TranslatorEngineInterface $fallback = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** Whether this pair would be translated in this process (as opposed to by the fallback, or not at all). */
    public function handlesInProcess(string $source, string $target): bool
    {
        return null === $this->translator->whyNot($source, $target);
    }

    /** Why this pair would not be translated in this process; null when it would. */
    public function whyNotInProcess(string $source, string $target): ?string
    {
        return $this->translator->whyNot($source, $target);
    }

    public function translate(TranslationRequest $req): TranslationResult
    {
        $why = $this->translator->whyNot($req->source, $req->target);
        if (null === $why) {
            try {
                $done = $this->translator->translate($req->text, $req->source, $req->target);

                return new TranslationResult($done->text, 'auto' === $req->source ? null : $req->source, [
                    'engine' => $this->name,
                    'via' => $this->name,
                    'in_process' => true,
                    'models' => $done->route,
                    'elapsed_ms' => $done->elapsedMs,
                    'tokens' => $done->tokens,
                    'sentences' => $done->sentences,
                    // Stays at one per model for the life of the process where it is kept loaded
                    // (FrankenPHP worker, messenger:consume).
                    'model_loads' => $done->modelLoads,
                ]);
            } catch (BergamotException $e) {
                // The model refused this text (too long a sentence, unknown tokens, ...): the fallback may still manage.
                $why = $e->getMessage();
            }
        }
        if (null === $this->fallback) {
            throw new TranslatorException($this->name.': '.$why);
        }
        $result = $this->fallback->translate($req);

        return new TranslationResult($result->translatedText, $result->detectedSource, [
            'via' => $this->fallback->getName(),
            'in_process' => false,
            'in_process_skipped' => $why,
        ] + $result->meta);
    }

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult
    {
        // A pair that cannot be done here at all goes to the fallback in one request rather than text by text.
        if (null !== ($why = $this->translator->whyNot($req->source, $req->target))) {
            if (null === $this->fallback) {
                throw new TranslatorException($this->name.': '.$why);
            }
            $result = $this->fallback->translateBatch($req);

            return new TranslationBatchResult($result->translatedTexts, $result->detectedSource, [
                'via' => $this->fallback->getName(),
                'in_process' => false,
                'in_process_skipped' => $why,
            ] + $result->meta);
        }

        $texts = [];
        $fellBack = 0;
        foreach ($req->texts as $text) {
            $result = $this->translate(new TranslationRequest($text, $req->source, $req->target, $req->html, extra: $req->extra));
            $fellBack += ($result->meta['in_process'] ?? false) ? 0 : 1;
            $texts[] = $result->translatedText;
        }

        return new TranslationBatchResult($texts, $req->source, [
            'engine' => $this->name,
            'via' => $this->name,
            'in_process' => $fellBack < \count($texts),
            'in_process_texts' => \count($texts) - $fellBack,
            'fallback_texts' => $fellBack,
        ]);
    }

    public function detect(string $text): LanguageDetectionResult
    {
        if (null === $this->fallback) {
            throw new TranslatorException($this->name.' does not detect languages and has no fallback engine that does');
        }

        return $this->fallback->detect($text);
    }

    public function capabilities(): EngineCapabilities
    {
        return new EngineCapabilities(supportsGlossary: false, supportsHtml: false, maxCharsPerRequest: 20000, meta: [
            'in_process' => true,
            'languages' => $this->translator->languages(),
            'fallback' => $this->fallback?->getName(),
        ]);
    }
}
