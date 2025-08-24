<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class TranslationBatchRequest
{
    /**
     * @param list<string>         $texts
     * @param array<string,mixed>  $extra
     */
    public function __construct(
        public readonly array $texts,
        public readonly string $source,
        public readonly string $target,
        public readonly bool $html = false,
        public readonly array $extra = [],
    ) {}
}
