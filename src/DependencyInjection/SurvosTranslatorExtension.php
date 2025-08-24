<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\DependencyInjection;

use Survos\TranslatorBundle\Engine\LibreTranslateEngine;
use Survos\TranslatorBundle\Service\{TranslatorRegistry, TranslatorManager};
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Reference;

final class SurvosTranslatorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $engineServiceIds = [];
        foreach ($config['engines'] ?? [] as $name => $cfg) {
            $type = $cfg['type'];

            // client (scoped) -> using http_client->withOptions
            $clientDef = new Definition(HttpClientInterface::class);
            $clientDef->setFactory([new Reference('http_client'), 'withOptions']);
            $options = [];
            if (!empty($cfg['base_uri'])) $options['base_uri'] = $cfg['base_uri'];
            $clientDef->setArguments([$options]);
            $clientId = sprintf('survos.translator.http_client.%s', $name);
            $container->setDefinition($clientId, $clientDef);

            if ($type === 'libre') {
                $engineDef = new Definition(LibreTranslateEngine::class);
                $engineDef
                    ->setArguments([
                        new Reference($clientId),
                        $name,
                        $cfg['api_key'] ?? null,
                    ])
                    ->setPublic(false);
                $engineId = sprintf('survos.translator.engine.%s', $name);
                $container->setDefinition($engineId, $engineDef);
                $engineServiceIds[$name] = $engineId;
            }

            // Other engines (bing/deepl/google) can be added similarly.
        }

        // ServiceLocator for engines
        $locatorMap = [];
        foreach ($engineServiceIds as $n => $id) {
            $locatorMap[$n] = new Reference($id);
        }
        $locatorArg = new ServiceLocatorArgument($locatorMap);

        $container->getDefinition(TranslatorManager::class)->setPublic(true);

        $registryDef = $container->getDefinition(TranslatorRegistry::class);
        $registryDef->setArguments([
            $locatorArg,
            $engineServiceIds,
            $config['default_engine'] ?? 'default'
        ])->setPublic(true);
    }
}
