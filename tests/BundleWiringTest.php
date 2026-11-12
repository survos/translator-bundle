<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\TranslatorBundle\Engine\InProcessEngine;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Model\TranslationRequest;
use Survos\TranslatorBundle\Service\TranslatorRegistry;
use Survos\TranslatorBundle\SurvosTranslatorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

/** The `bergamot` engine type as an application configures it: a named engine with a fallback engine behind it. */
final class BundleWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir().'/survos-translator-bundle-test');
    }

    public function testABergamotEngineIsRegisteredWithItsFallback(): void
    {
        $kernel = new TranslatorTestKernel(['libre' => ['type' => 'libre', 'base_uri' => 'https://libre.test'], 'native' => [
            'type' => 'bergamot', 'home' => '/nonexistent', 'fallback' => 'libre',
        ]]);
        $kernel->boot();
        try {
            $registry = $kernel->getContainer()->get(TranslatorRegistry::class);
            self::assertSame(['libre', 'native'], $registry->names());
            $engine = $registry->get('native');
            self::assertInstanceOf(InProcessEngine::class, $engine);
            self::assertInstanceOf(LibreTranslateEngine::class, $registry->get('libre'));

            $meta = $engine->capabilities()->meta;
            self::assertSame('libre', $meta['fallback']);
            self::assertContains('de', $meta['languages']);
            self::assertContains('en', $meta['languages']);
            self::assertFalse($engine->handlesInProcess('de', 'en'), 'nothing is installed under /nonexistent');
            self::assertStringContainsString('missing /nonexistent', (string) $engine->whyNotInProcess('de', 'en'));
            self::assertStringContainsString('cannot write "hu"', (string) $engine->whyNotInProcess('en', 'hu'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testWithoutAFallbackAnUninstalledModelIsAnErrorThatSaysWhy(): void
    {
        $kernel = new TranslatorTestKernel(['native' => ['type' => 'bergamot', 'home' => '/nonexistent']]);
        $kernel->boot();
        try {
            $engine = $kernel->getContainer()->get(TranslatorRegistry::class)->get('native');
            $this->expectExceptionMessage('missing /nonexistent');
            $engine->translate(new TranslationRequest('Guten Morgen.', 'de', 'en'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAFallbackThatIsNotAConfiguredEngineIsRejectedWhenTheContainerIsBuilt(): void
    {
        $kernel = new TranslatorTestKernel(['native' => ['type' => 'bergamot', 'home' => '/x', 'fallback' => 'nobody']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('fallback must name another configured engine, got "nobody"');
        $kernel->boot();
    }
}

final class TranslatorTestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, array<string, mixed>> $engines */
    public function __construct(private readonly array $engines)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SurvosTranslatorBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false]);
        $container->extension('survos_translator', ['default_engine' => 'native', 'engines' => $this->engines]);
    }

    /** Outside the bundle: a debug kernel writes config/reference.php into its project directory. */
    public function getProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/survos-translator-bundle-test/project';
        is_dir($dir) || mkdir($dir, 0777, true);

        return $dir;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/survos-translator-bundle-test/'.md5(serialize($this->engines)).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/survos-translator-bundle-test/log';
    }
}
