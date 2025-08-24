<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle;

use Survos\TranslatorBundle\Command\TranslatorTestCommand;
use Psr\Cache\CacheItemPoolInterface;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Engine\DeepLEngine;
use Survos\TranslatorBundle\Engine\GoogleTranslateEngine;
use Survos\TranslatorBundle\Service\{TranslatorRegistry, TranslatorManager};
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SurvosTranslatorBundle extends AbstractBundle implements CompilerPassInterface
{
public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_engine')
                    ->defaultValue('libre_local')
                    ->info('Name of the engine to use by default (e.g. "libre_local").')
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')
                            ->defaultNull()
                            ->info('CacheItemPoolInterface service id (e.g. "cache.translator"). Leave null to disable caching.')
                        ->end()
                        ->integerNode('ttl')
                            ->defaultValue(0)
                            ->min(0)
                            ->info('Default TTL (seconds) for cached translations. 0 = no expiration.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('engines')
                    ->info(<<<'INFO'
Configure one or more named engines. Examples (commented-out):

  survos_translator:
    default_engine: libre_local
    engines:
      # LibreTranslate (no API key required by default)
      # libre_local:
      #   type: libre
      #   base_uri: 'http://localhost:5000'
      #   api_key: null

      # DeepL (API key REQUIRED; host inferred from plan when base_uri is omitted)
      # deepl_free:
      #   type: deepl
      #   plan: free            # or "pro"
      #   api_key: '%env(DEEPL_API_KEY)%'

      # Google Cloud Translate (API key REQUIRED; host inferred when base_uri is omitted)
      # google:
      #   type: google
      #   api_key: '%env(GOOGLE_TRANSLATE_KEY)%'

      # Bing (API key REQUIRED; region often required; host can be inferred in the engine)
      # bing_global:
      #   type: bing
      #   region: 'global'
      #   api_key: '%env(BING_TRANSLATOR_KEY)%'
INFO)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')
                                ->values(['libre','bing','deepl','google'])
                                ->isRequired()
                                ->info('Provider. API key is REQUIRED for deepl/google/bing; optional for libre.')
                            ->end()
                            ->scalarNode('base_uri')
                                ->defaultNull()
                                ->info('Optional. If null, sensible defaults are used for deepl/google/bing; for self-hosted LibreTranslate, set your host.')
                            ->end()
                            ->scalarNode('api_key')
                                ->defaultNull()
                                ->info('Provider API key (use env vars like %env(DEEPL_API_KEY)%). Required for deepl/google/bing; optional for libre.')
                            ->end()
                            ->scalarNode('region')
                                ->defaultNull()
                                ->info('Some providers (e.g. Bing) require a region (e.g. "global").')
                            ->end()
                            ->scalarNode('plan')
                                ->defaultNull()
                                ->info('For DeepL: "free" or "pro". Determines default host if base_uri is not set.')
                            ->end()
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

            // Compute default base URIs if not provided
            $baseUri = (string)($cfg['base_uri'] ?? '');
            if ($baseUri === '') {
                if ($type === 'deepl') {
                    $plan = strtolower((string)($cfg['plan'] ?? 'free'));
                    $baseUri = $plan === 'pro' ? 'https://api.deepl.com' : 'https://api-free.deepl.com';
                } elseif ($type === 'google') {
                    $baseUri = 'https://translation.googleapis.com';
                }
                // libre/google with custom hosts are still supported via base_uri in config
            }

            // Scoped HttpClient for this engine
            $clientId = sprintf('survos.translator.http_client.%s', $name);
            $builder->register($clientId, HttpClientInterface::class)
                ->setFactory([new Reference('http_client'), 'withOptions'])
                ->setArguments([[ 'base_uri' => $baseUri ?: ($cfg['base_uri'] ?? null) ]])
                ->setPublic(false);

            $engineId = null;

            if ($type === 'libre') {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, LibreTranslateEngine::class)
                    ->setArguments([
                        new Reference($clientId),
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,              // ?CacheItemPoolInterface
                        $defaultTtl,            // int
                        (string)$baseUri,
                    ])
                    ->setPublic(false);
            } elseif ($type === 'deepl') {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, DeepLEngine::class)
                    ->setArguments([
                        new Reference($clientId),
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,
                        $defaultTtl,
                        (string)$baseUri,
                    ])
                    ->setPublic(false);
            } elseif ($type === 'google') {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, GoogleTranslateEngine::class)
                    ->setArguments([
                        new Reference($clientId),
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,
                        $defaultTtl,
                        (string)$baseUri,
                    ])
                    ->setPublic(false);
            } else {
                // 'bing' or unknown placeholder for future engines
                continue;
            }

            $engineServiceIds[$name] = $engineId;
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
