<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class TranslationRequest
{
    public function __construct(
        public readonly string $text,
        public readonly string $source,   // 'auto' allowed
        public readonly string $target,
        public readonly bool $html = false,
        public readonly ?string $glossaryId = null,
        public readonly array $extra = [],
    ) {}
}

final class TranslationResult
{
    public function __construct(
        public readonly string $translatedText,
        public readonly string $detectedSource,
        public readonly array $meta = [],
    ) {}
}

final class TranslationBatchRequest
{
    /** @param list<string> $texts */
    public function __construct(
        public readonly array $texts,
        public readonly string $source,
        public readonly string $target,
        public readonly bool $html = false,
        public readonly array $extra = [],
    ) {}
}

final class TranslationBatchResult
{
    /** @param list<string> $translatedTexts */
    public function __construct(
        public readonly array $translatedTexts,
        public readonly string $detectedSource,
        public readonly array $meta = [],
    ) {}
}

final class LanguageDetectionResult
{
    public function __construct(
        public readonly string $language,
        public readonly float $confidence,
        public readonly array $meta = [],
    ) {}
}

final class EngineCapabilities
{
    public function __construct(
        public readonly bool $supportsGlossary = false,
        public readonly bool $supportsHtml = true,
        public readonly ?int $maxCharsPerRequest = null,
        public readonly array $meta = [],
    ) {}
}
