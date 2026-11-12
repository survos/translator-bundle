<?php

declare(strict_types=1);

namespace Survos\TranslatorBundle;

use Psr\Cache\CacheItemPoolInterface;
use Survos\Bergamot\ModelManager;
use Survos\Bergamot\Runtime;
use Survos\Bergamot\Translator;
use Survos\TranslatorBundle\Command\BergamotModelsCommand;
use Survos\TranslatorBundle\Command\TranslatorTestCommand;
use Survos\TranslatorBundle\Engine\DeepLEngine;
use Survos\TranslatorBundle\Engine\GoogleTranslateEngine;
use Survos\TranslatorBundle\Engine\InProcessEngine;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Retry\RateLimitAwareRetryStrategy;
use Survos\TranslatorBundle\Service\TranslatorManager;
use Survos\TranslatorBundle\Service\TranslatorRegistry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

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

      # Bergamot models inside this PHP process, through FFI (survos/bergamot): no HTTP. Used when the models are
      # installed on this machine and cover the language pair; everything else goes to `fallback`.
      # euronano:
      #   type: bergamot
      #   home: '%env(resolve:BERGAMOT_HOME)%'   # holds native/ and models/
      #   fallback: libre_local
INFO)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')
                                ->values(['libre', 'bing', 'deepl', 'google', 'bergamot'])
                                ->isRequired()
                                ->info('Provider. API key is REQUIRED for deepl/google/bing; optional for libre. "bergamot" runs a model in this process and needs "home".')
                            ->end()
                            ->scalarNode('home')
                                ->defaultNull()
                                ->info('bergamot: directory holding native/ (the C bridge and the runtime library) and models/.')
                            ->end()
                            ->enumNode('variant')
                                ->values(['tiny', 'base', 'base-memory'])
                                ->defaultValue('base')
                                ->info('bergamot: model size. Install models with `bin/console translator:models --install`.')
                            ->end()
                            ->scalarNode('fallback')
                                ->defaultNull()
                                ->info('bergamot: name of another configured engine that takes what cannot be translated in-process (model not installed here, pair not covered, text refused).')
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
     * The scoped HttpClient for each configured engine is defined in the FRAMEWORK
     * (framework.yaml's http_client.scoped_clients), not built by hand here — this is where
     * base_uri gets resolved per engine and where retry_failed is turned on, so every engine
     * gets the full stock Symfony HttpClient decorator stack (scoping, retry, tracing, ...) for
     * free instead of us re-implementing a slice of it. loadExtension() below just references
     * the resulting client by name; it never constructs one.
     *
     * prependExtension() runs before this bundle's own Configuration tree is normally resolved,
     * so the engines list is read as raw config via getExtensionConfig() -- same pattern as
     * SurvosLocBundle's single scoped client, extended here to one scoped client per
     * dynamically-configured engine name.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);

        $config = self::rawConfig($builder);
        $scopedClients = [];
        foreach ($config['engines'] ?? [] as $name => $cfg) {
            $baseUri = self::resolveBaseUri((string) ($cfg['type'] ?? ''), $cfg);
            if ('' === $baseUri) {
                continue; // no base_uri resolvable (e.g. libre with no host configured yet) -- nothing to scope
            }

            $scopedClients[self::clientId($name)] = [
                'base_uri' => $baseUri,
                // Any engine (libre/deepl/google/...) can be rate-limited by its remote API --
                // retried at the HTTP client layer, not just Messenger's transport-level retry
                // (which just re-fires the identical request and can collapse into a storm).
                'retry_failed' => [
                    'enabled' => true,
                    // http_codes/delay/multiplier/etc. can't be combined with retry_strategy --
                    // RateLimitAwareRetryStrategy owns which codes retry (inherits
                    // GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, a superset including
                    // 429/500/502/503/504) and honors a Retry-After header on top of that
                    // instead of always falling back to blind exponential backoff.
                    'retry_strategy' => RateLimitAwareRetryStrategy::class,
                ],
            ];
        }

        if ([] !== $scopedClients) {
            $builder->prependExtensionConfig('framework', [
                'http_client' => ['scoped_clients' => $scopedClients],
            ]);
        }
    }

    private static function clientId(string $engineName): string
    {
        return sprintf('survos_translator.%s', $engineName);
    }

    /** @param array<string,mixed> $cfg */
    private static function resolveBaseUri(string $type, array $cfg): string
    {
        $baseUri = (string) ($cfg['base_uri'] ?? '');
        if ('' !== $baseUri) {
            return $baseUri;
        }

        return match ($type) {
            'deepl' => 'pro' === strtolower((string) ($cfg['plan'] ?? 'free'))
                ? 'https://api.deepl.com'
                : 'https://api-free.deepl.com',
            'google' => 'https://translation.googleapis.com',
            default => '', // libre (self-hosted) and unknown types must set base_uri explicitly
        };
    }

    /** Reads `survos_translator.*` before the config tree is processed — see SurvosLocBundle for the same pattern. */
    private static function rawConfig(ContainerBuilder $builder): array
    {
        $merged = [];
        foreach ($builder->getExtensionConfig('survos_translator') as $config) {
            $merged = array_merge($merged, $config);
        }

        return $merged;
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
        // Referenced by name from prependExtension()'s retry_failed.retry_strategy config --
        // one shared, stateless instance covers every engine's scoped client.
        $builder->autowire(RateLimitAwareRetryStrategy::class)->setPublic(false);

        // Optional cache pool
        $cacheRef = null;
        $cachePoolId = $config['cache']['pool'] ?? null;
        $defaultTtl = (int) ($config['cache']['ttl'] ?? 0);
        if (\is_string($cachePoolId) && '' !== $cachePoolId) {
            $cacheRef = new Reference($cachePoolId);
        }

        // Register engines
        $engineServiceIds = [];
        $bergamot = [];
        foreach ($config['engines'] ?? [] as $name => $cfg) {
            $type = (string) $cfg['type'];
            $baseUri = self::resolveBaseUri($type, $cfg);
            // The scoped, retry-wrapped client prepended into framework.yaml above -- built by
            // FrameworkBundle itself, not by this bundle.
            $clientRef = new Reference(self::clientId($name));

            $engineId = null;

            if ('libre' === $type) {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, LibreTranslateEngine::class)
                    ->setArguments([
                        $clientRef,
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,              // ?CacheItemPoolInterface
                        $defaultTtl,            // int
                        (string) $baseUri,
                    ])
                    ->setPublic(false);
            } elseif ('deepl' === $type) {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, DeepLEngine::class)
                    ->setArguments([
                        $clientRef,
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,
                        $defaultTtl,
                        (string) $baseUri,
                    ])
                    ->setPublic(false);
            } elseif ('google' === $type) {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, GoogleTranslateEngine::class)
                    ->setArguments([
                        $clientRef,
                        $name,
                        $cfg['api_key'] ?? null,
                        $cacheRef,
                        $defaultTtl,
                        (string) $baseUri,
                    ])
                    ->setPublic(false);
            } elseif ('bergamot' === $type) {
                $fallback = $cfg['fallback'] ?? null;
                if (null !== $fallback) {
                    $fallbackType = $config['engines'][$fallback]['type'] ?? null;
                    if ($fallback === $name || !\in_array($fallbackType, ['libre', 'deepl', 'google', 'bergamot'], true)) {
                        throw new InvalidConfigurationException(sprintf('survos_translator.engines.%s.fallback must name another configured engine, got "%s".', $name, $fallback));
                    }
                }
                $home = (string) ($cfg['home'] ?? '');
                $managerId = sprintf('survos.translator.bergamot.%s.models', $name);
                $builder->register($managerId, ModelManager::class)->setArguments([$home])->setPublic(false);
                $runtimeId = sprintf('survos.translator.bergamot.%s.runtime', $name);
                $builder->register($runtimeId, Runtime::class)->setArguments([$home])->setPublic(false);
                $translatorId = sprintf('survos.translator.bergamot.%s', $name);
                $builder->register($translatorId, Translator::class)
                    ->setArguments([new Reference($managerId), new Reference($runtimeId), (string) $cfg['variant']])
                    ->setPublic(false);
                $engineId = sprintf('survos.translator.engine.%s', $name);
                // No cache pool on purpose: a call costs milliseconds and the application's translation memory
                // is the one place a finished translation is kept.
                $builder->register($engineId, InProcessEngine::class)
                    ->setArguments([
                        new Reference($translatorId),
                        $name,
                        null === $fallback ? null : new Reference(sprintf('survos.translator.engine.%s', $fallback)),
                    ])
                    ->setPublic(false);
                $bergamot[$name] = ['models' => $managerId, 'variant' => (string) $cfg['variant']];
            } else {
                // 'bing' or unknown placeholder for future engines
                continue;
            }

            $engineServiceIds[$name] = $engineId;
        }

        // translator:models manages the models of every bergamot engine.
        $managers = [];
        foreach ($bergamot as $n => $info) {
            $managers[$n] = new Reference($info['models']);
        }
        $builder->autowire(BergamotModelsCommand::class)
            ->setArgument('$managers', new ServiceLocatorArgument($managers))
            ->setArgument('$variants', array_map(static fn (array $i): string => $i['variant'], $bergamot))
            ->addTag('console.command');

        // ServiceLocator for registry
        $locatorMap = [];
        foreach ($engineServiceIds as $n => $id) {
            $locatorMap[$n] = new Reference($id);
        }
        $builder->getDefinition(TranslatorRegistry::class)
            ->setArguments([
                new ServiceLocatorArgument($locatorMap),
                $engineServiceIds,
                (string) ($config['default_engine'] ?? 'default'),
            ]);

        // Make engine map available to compiler pass
        $builder->setParameter('survos.translator.engine_map', $engineServiceIds);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION);
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
            $var = $varBase.'Translator';             // e.g. $libreLocalTranslator
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
        $rest = array_map(static fn ($p) => ucfirst(strtolower($p)), $parts);

        return strtolower($first).implode('', $rest);
    }
}
