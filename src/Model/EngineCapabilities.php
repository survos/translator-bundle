<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Model;

final class EngineCapabilities
{
    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly bool $supportsGlossary = false,
        public readonly bool $supportsHtml = true,
        public readonly ?int $maxCharsPerRequest = null,
        public readonly array $meta = [],
    ) {}
}
