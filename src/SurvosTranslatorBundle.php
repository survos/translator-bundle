<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle;

use Survos\TranslatorBundle\Command\TranslatorTestCommand;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Service\{TranslatorRegistry, TranslatorManager};
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosTranslatorBundle extends AbstractBundle implements CompilerPassInterface
{
   public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_engine')->defaultValue('default')->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')->defaultNull()->end() // CacheItemPoolInterface service id or null
                        ->integerNode('ttl')->defaultValue(0)->min(0)->end() // seconds; 0 = no expiration
                    ->end()
                ->end()
                ->arrayNode('engines')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')->values(['libre','bing','deepl','google'])->isRequired()->end()
                            ->scalarNode('base_uri')->defaultNull()->end()
                            ->scalarNode('api_key')->defaultNull()->end()
                            ->scalarNode('region')->defaultNull()->end()
                            ->scalarNode('plan')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string,mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Core services
        $builder->register(TranslatorRegistry::class)->setPublic(true);
        $builder->register(TranslatorManager::class)->setPublic(true)->setAutowired(true);

        foreach ([TranslatorTestCommand::class] as $class) {
            $builder->autowire($class)
                ->setAutoconfigured(true)
                ->addTag('console.command');

        }

        // Optional cache pool
        $cacheRef = null;
        $cachePoolId = $config['cache']['pool'] ?? null;
        $defaultTtl  = (int)($config['cache']['ttl'] ?? 0);
        if (\is_string($cachePoolId) && $cachePoolId !== '') {
            $cacheRef = new Reference($cachePoolId);
        }

        // Register engines
        $engineServiceIds = [];
        foreach ($config['engines'] ?? [] as $name => $cfg) {
            $type = (string)$cfg['type'];

            // Scoped HttpClient for this engine
            $clientId = sprintf('survos.translator.http_client.%s', $name);
            $builder->register($clientId, HttpClientInterface::class)
                ->setFactory([new Reference('http_client'), 'withOptions'])
                ->setArguments([[ 'base_uri' => $cfg['base_uri'] ?? null ]])
                ->setPublic(false);

            if ($type === 'libre') {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, LibreTranslateEngine::class)
                    ->setArguments([
                        new Reference($clientId),
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,              // ?CacheItemPoolInterface
                        $defaultTtl,            // int
                        (string)($cfg['base_uri'] ?? ''),
                    ])
                    ->setAutowired(false)
                    ->setAutoconfigured(false)
                    ->setPublic(false);

                $engineServiceIds[$name] = $engineId;
            }
            // TODO: add bing/deepl/google engines following the same pattern
        }

        // ServiceLocator for registry
        $locatorMap = [];
        foreach ($engineServiceIds as $n => $id) {
            $locatorMap[$n] = new Reference($id);
        }
        $builder->getDefinition(TranslatorRegistry::class)
            ->setArguments([
                new ServiceLocatorArgument($locatorMap),
                $engineServiceIds,
                (string)($config['default_engine'] ?? 'default'),
            ]);

        // Make engine map available to compiler pass
        $builder->setParameter('survos.translator.engine_map', $engineServiceIds);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('survos.translator.engine_map')) {
            return;
        }
        /** @var array<string,string> $map */
        $map = $container->getParameter('survos.translator.engine_map');

        $iface = 'Survos\\TranslatorBundle\\Contract\\TranslatorEngineInterface';
        foreach ($map as $name => $serviceId) {
            $varBase = $this->camelize($name);            // e.g. libre_local -> libreLocal
            $var     = $varBase.'Translator';             // e.g. $libreLocalTranslator
            $aliasId = $iface.' $'.$var;                  // autowire-by-name alias id

            if (!$container->hasAlias($aliasId)) {
                $container->setAlias($aliasId, $serviceId)->setPublic(false);
            }

            // Also expose a generic id per engine (handy for manual wiring)
            $id = sprintf('survos.translator.%s', $name);
            if (!$container->hasAlias($id) && !$container->hasDefinition($id)) {
                $container->setAlias($id, $serviceId)->setPublic(false);
            }
        }
    }

    private function camelize(string $name): string
    {
        $name = str_replace(['-', '.'], '_', $name);
        $parts = array_filter(explode('_', $name), 'strlen');
        $first = array_shift($parts) ?? '';
        $rest = array_map(static fn($p) => ucfirst(strtolower($p)), $parts);
        return strtolower($first) . implode('', $rest);
    }
}
