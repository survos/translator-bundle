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

#[AsCommand('survos:translator:test', 'Quick smoke test for an engine')]
final class TranslatorTestCommand
{
    public function __construct(private readonly TranslatorManager $manager) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Text to translate')] string $text,
        #[Option('Engine name (defaults to config default)')] ?string $engine = null,
        #[Option('Source language, e.g. en or auto')] string $from = 'auto',
        #[Option('Target language, e.g. es')] string $to = 'es',
        #[Option('Treat input as HTML')] bool $html = false,
    ): int {
        $eng = $engine ? $this->manager->by($engine) : $this->manager->default();

        $req = new TranslationRequest(
            text: $text,
            source: $from,
            target: $to,
            html: $html,
        );

        $res = $eng->translate($req);

        $io->writeln(sprintf('<info>[%s]</info> %s', $eng->getName(), $res->translatedText));
        if ($res->detectedSource !== '' && $from === 'auto') {
            $io->comment('Detected: ' . $res->detectedSource);
        }

        return Command::SUCCESS;
    }
}
