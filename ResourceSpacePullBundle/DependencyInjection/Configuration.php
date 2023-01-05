<?php

namespace ResourceSpacePullBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('resource_space_pull');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('resource_space')
                    ->children()
                        ->scalarNode('resource_url')->end()
                        ->scalarNode('resource_user')->end()
                        ->scalarNode('resource_apikey')->end()
                    ->end()
                ->end() // resource_space
                ->scalarNode('tmp_template_upload_pimcore_dir')->end()
                ->scalarNode('asset_folder')->end()
                ->scalarNode('in_progress_process_life')->end()
                ->scalarNode('max_parallel_active_process_count')->end()
                ->scalarNode('schedule_screen_refresh_time')->end()
            ->end();

        return $treeBuilder;
    }
}
