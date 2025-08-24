<?php
declare(strict_types=1);

namespace Survos\TranslatorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('survos_translator');
        $root = $tb->getRootNode();

        $root
            ->children()
                ->scalarNode('default_engine')->defaultValue('default')->end()
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

        return $tb;
    }
}
