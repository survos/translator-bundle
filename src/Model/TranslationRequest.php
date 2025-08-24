<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class TranslationRequest
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly string $text,
        public readonly string $source,   // 'auto' allowed
        public readonly string $target,
        public readonly bool $html = false,
        public readonly ?string $glossaryId = null,
        public readonly array $extra = [],
    ) {}
}
