<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class LanguageDetectionResult
{
    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $language,
        public readonly float $confidence,
        public readonly array $meta = [],
    ) {}
}
