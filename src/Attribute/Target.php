<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Target
{
    public function __construct(public string $name) {}
}
