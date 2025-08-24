<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Contract;

use Survos\TranslatorBundle\Model\{
    TranslationRequest, TranslationResult,
    TranslationBatchRequest, TranslationBatchResult,
    LanguageDetectionResult, EngineCapabilities
};

interface TranslatorEngineInterface
{
    public function getName(): string;

    public function translate(TranslationRequest $req): TranslationResult;

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult;

    public function detect(string $text): LanguageDetectionResult;

    public function capabilities(): EngineCapabilities;
}
