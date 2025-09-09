<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class TranslationResult
{
    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $translatedText,
        public readonly ?string $detectedSource=null,
        public readonly array $meta = [],
    ) {}
}
