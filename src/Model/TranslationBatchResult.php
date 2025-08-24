<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class TranslationBatchResult
{
    /**
     * @param list<string>         $translatedTexts
     * @param array<string,mixed>  $meta
     */
    public function __construct(
        public readonly array $translatedTexts,
        public readonly string $detectedSource,
        public readonly array $meta = [],
    ) {}
}
