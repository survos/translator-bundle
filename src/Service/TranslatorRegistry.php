<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Service;

use Psr\Container\ContainerInterface;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Exception\EngineNotFoundException;

final class TranslatorRegistry
{
    /** @param array<string,string> $engineServiceIds */
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly array $engineServiceIds,
        private readonly string $defaultEngine,
    ) {}

    /** @return list<string> */
    public function names(): array { return array_keys($this->engineServiceIds); }

    public function defaultName(): string { return $this->defaultEngine; }

    public function get(string $name): TranslatorEngineInterface
    {
        if (!$this->locator->has($name)) {
            throw new EngineNotFoundException("Translator engine not found: {$name}");
        }
        /** @var TranslatorEngineInterface $engine */
        $engine = $this->locator->get($name);
        return $engine;
    }

    public function getDefault(): TranslatorEngineInterface
    {
        return $this->get($this->defaultEngine);
    }
}
