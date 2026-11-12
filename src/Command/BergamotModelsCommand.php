<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Command;

use Psr\Container\ContainerInterface;
use Survos\Bergamot\Model;
use Survos\Bergamot\ModelManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('translator:models', 'List, install or verify the models of the in-process (bergamot) engines')]
final class BergamotModelsCommand
{
    /**
     * @param ContainerInterface    $managers one ModelManager per bergamot engine, by engine name
     * @param array<string, string> $variants engine name => configured model size
     */
    public function __construct(
        private readonly ContainerInterface $managers,
        private readonly array $variants,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Download the models the configured engines need (both directions of their size)')] bool $install = false,
        #[Option('Re-hash the installed models against the pinned checksums')] bool $verify = false,
    ): int {
        if ([] === $this->variants) {
            $io->warning('No engine of type "bergamot" is configured under survos_translator.engines.');

            return Command::SUCCESS;
        }
        $ok = true;
        foreach ($this->variants as $engine => $variant) {
            /** @var ModelManager $manager */
            $manager = $this->managers->get($engine);
            $io->section(sprintf('%s: %s models in %s/models', $engine, $variant, $manager->home));
            $rows = [];
            foreach ($manager->catalog()->all() as $model) {
                $needed = $model->variant === $variant;
                if ($install && $needed && !$manager->isInstalled($model)) {
                    $io->writeln(sprintf('Downloading %s (%.0f MB) from %s ...', $model->id, $model->bytes() / 1e6, $model->source));
                    $manager->install($model);
                }
                $state = $manager->isInstalled($model) ? 'installed' : ($needed ? 'MISSING' : '-');
                if ($verify && $manager->isInstalled($model)) {
                    $bad = array_keys(array_filter($manager->verify($model), static fn (bool $intact): bool => !$intact));
                    $state = [] === $bad ? 'verified' : 'CORRUPT: '.implode(', ', $bad);
                    $ok = $ok && [] === $bad;
                }
                $rows[] = [$model->id, Model::XX_EN === $model->direction ? 'into English' : 'from English', sprintf('%.0f MB', $model->bytes() / 1e6), $needed ? 'yes' : '', $state];
            }
            $io->table(['model', 'direction', 'size', 'used', 'state'], $rows);
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
