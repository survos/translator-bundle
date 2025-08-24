<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Service;

use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;

final class TranslatorManager
{
    public function __construct(private readonly TranslatorRegistry $registry) {}

    public function default(): TranslatorEngineInterface { return $this->registry->getDefault(); }

    public function by(string $name): TranslatorEngineInterface { return $this->registry->get($name); }

    /** @return list<string> */
    public function names(): array { return $this->registry->names(); }

    public function defaultName(): string { return $this->registry->defaultName(); }
}
