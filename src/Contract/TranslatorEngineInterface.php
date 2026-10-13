<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Contract;

use Survos\TranslatorBundle\Model\EngineCapabilities;
use Survos\TranslatorBundle\Model\LanguageDetectionResult;
use Survos\TranslatorBundle\Model\TranslationBatchRequest;
use Survos\TranslatorBundle\Model\TranslationBatchResult;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Model\TranslationResult;

interface TranslatorEngineInterface
{
    public function getName(): string;

    public function translate(TranslationRequest $req): TranslationResult;

    public function translateBatch(TranslationBatchRequest $req): TranslationBatchResult;

    public function detect(string $text): LanguageDetectionResult;

    public function capabilities(): EngineCapabilities;
}
