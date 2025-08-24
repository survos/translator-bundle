<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\Command;

use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Service\TranslatorManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;

#[AsCommand('survos:translator:test', 'Quick smoke test for one or more engines')]
final class TranslatorTestCommand
{
    public function __construct(private readonly TranslatorManager $manager) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Text to translate')] string $text,
        #[Option('Engine name (defaults to config default; choose interactively if multiple)')] ?string $engine = null,
        #[Option('Source language, e.g. en or auto')] string $from = 'auto',
        #[Option('Target language, e.g. es')] string $to = 'es',
        #[Option('Treat input as HTML')] bool $html = false,
        #[Option('Translate with all configured engines and compare results')] bool $all = false,
    ): int {
        $names = $this->manager->names();

        if ($all) {
            if (\count($names) === 0) {
                $io->error('No translator engines are configured.');
                return Command::FAILURE;
            }
            $rows = [];
            foreach ($names as $name) {
                $eng = $this->manager->by($name);
                $res = $eng->translate(new TranslationRequest($text, $from, $to, $html));
                $rows[] = [$eng->getName(), $res->detectedSource, $res->translatedText];
            }
            $io->table(['Engine', 'Detected', 'Translation'], $rows);
            return Command::SUCCESS;
        }

        // Single engine path
        if ($engine === null) {
            if (\count($names) === 0) {
                $io->error('No translator engines are configured.');
                return Command::FAILURE;
            } elseif (\count($names) === 1) {
                $engine = $names[0];
            } else {
                $default = $this->manager->defaultName();
                $engine = $io->choice('Select a translator engine', $names, $default);
            }
        }

        $eng = $this->manager->by($engine);
        $res = $eng->translate(new TranslationRequest($text, $from, $to, $html));

        $io->writeln(sprintf('<info>[%s]</info> %s', $eng->getName(), $res->translatedText));
        if ($res->detectedSource !== '' && $from === 'auto') {
            $io->comment('Detected: ' . $res->detectedSource);
        }

        return Command::SUCCESS;
    }
}
