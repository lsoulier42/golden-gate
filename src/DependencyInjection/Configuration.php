<?php

namespace App\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for the `golden_gate` root node (see config/packages/golden_gate.yaml).
 *
 * Providers describe how to reach an upstream LLM API. Models map a stable public
 * alias exposed by the gateway onto one upstream provider + model, with the prices
 * used to estimate the cost of each request.
 *
 * The ModelConfig shape keeps the optional keys optional: ModelRegistry is also
 * constructed directly, with only the required keys, in tests and scripts.
 *
 * @phpstan-type ProviderConfig array{
 *     type: string,
 *     base_url: string,
 *     api_key: string,
 *     timeout: float,
 * }
 * @phpstan-type ModelConfig array{
 *     provider: string,
 *     upstream_model: string,
 *     enabled?: bool,
 *     input_price_per_million_usd?: float,
 *     output_price_per_million_usd?: float,
 *     description?: string,
 *     context_window?: int|null,
 * }
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('golden_gate');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('providers')
                    ->info('Upstream LLM APIs reachable by the gateway, keyed by provider name.')
                    ->useAttributeAsKey('name')
                    // Provider names and model aliases are public identifiers: keep
                    // their dashes instead of letting Symfony normalize them.
                    ->normalizeKeys(false)
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')
                                ->info('Symfony AI bridge used to talk to the provider.')
                                ->values(['generic', 'mistral'])
                                ->defaultValue('generic')
                            ->end()
                            ->scalarNode('base_url')
                                ->info('Root URL of the provider API, without the endpoint path.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('api_key')
                                ->info('Provider credential; must reference an environment variable or a secret.')
                                ->defaultValue('')
                            ->end()
                            ->floatNode('timeout')
                                ->info('Maximum number of seconds a provider request may take.')
                                ->defaultValue(30.0)
                                ->min(0.1)
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('models')
                    ->info('Public models exposed by the gateway, keyed by public alias.')
                    ->useAttributeAsKey('alias')
                    ->normalizeKeys(false)
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('provider')
                                ->info('Name of an entry declared under `providers`.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('upstream_model')
                                ->info('Model identifier as understood by the provider.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->booleanNode('enabled')
                                ->info('Disabled models stay declared but are not served.')
                                ->defaultTrue()
                            ->end()
                            ->floatNode('input_price_per_million_usd')
                                ->info('Price in USD for one million input (prompt) tokens.')
                                ->defaultValue(0.0)
                                ->min(0.0)
                            ->end()
                            ->floatNode('output_price_per_million_usd')
                                ->info('Price in USD for one million output (completion) tokens.')
                                ->defaultValue(0.0)
                                ->min(0.0)
                            ->end()
                            ->scalarNode('description')
                                ->info('Free-form description surfaced by GET /v1/models and the admin UI.')
                                ->defaultValue('')
                            ->end()
                            ->integerNode('context_window')
                                ->info('Advertised context window in tokens, when known.')
                                ->defaultNull()
                                ->min(1)
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
