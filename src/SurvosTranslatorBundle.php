<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle;

use Survos\TranslatorBundle\Command\TranslatorTestCommand;
use Psr\Cache\CacheItemPoolInterface;
use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Engine\DeepLEngine;
use Survos\TranslatorBundle\Engine\GoogleTranslateEngine;
use Survos\TranslatorBundle\Retry\RateLimitAwareRetryStrategy;
use Survos\TranslatorBundle\Service\{TranslatorRegistry, TranslatorManager};
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
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
            $baseUri = self::resolveBaseUri((string)($cfg['type'] ?? ''), $cfg);
            if ($baseUri === '') {
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

        if ($scopedClients !== []) {
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
        $baseUri = (string)($cfg['base_uri'] ?? '');
        if ($baseUri !== '') {
            return $baseUri;
        }

        return match ($type) {
            'deepl' => strtolower((string)($cfg['plan'] ?? 'free')) === 'pro'
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
        $defaultTtl  = (int)($config['cache']['ttl'] ?? 0);
        if (\is_string($cachePoolId) && $cachePoolId !== '') {
            $cacheRef = new Reference($cachePoolId);
        }

        // Register engines
        $engineServiceIds = [];
        foreach ($config['engines'] ?? [] as $name => $cfg) {
            $type = (string)$cfg['type'];
            $baseUri = self::resolveBaseUri($type, $cfg);
            // The scoped, retry-wrapped client prepended into framework.yaml above -- built by
            // FrameworkBundle itself, not by this bundle.
            $clientRef = new Reference(self::clientId($name));

            $engineId = null;

            if ($type === 'libre') {
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $builder->register($engineId, LibreTranslateEngine::class)
                    ->setArguments([
                        $clientRef,
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
                        $clientRef,
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
                        $clientRef,
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
