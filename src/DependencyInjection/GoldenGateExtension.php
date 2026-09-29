<?php

namespace App\DependencyInjection;

use App\AI\Provider\ProviderDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Loads the `golden_gate` configuration.
 *
 * Providers become tagged ProviderDefinition services so their API keys stay
 * environment placeholders resolved at runtime, never compiled into the container.
 * Models hold no secret and are exposed as a plain parameter. The registries that
 * consume both are wired in config/services.yaml.
 *
 * @phpstan-import-type ProviderConfig from Configuration
 * @phpstan-import-type ModelConfig from Configuration
 */
final class GoldenGateExtension extends Extension
{
    public const PROVIDER_TAG = 'golden_gate.provider';
    public const MODELS_PARAMETER = 'golden_gate.models';

    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        /** @var array<string, ProviderConfig> $providers */
        $providers = $config['providers'];
        /** @var array<string, ModelConfig> $models */
        $models = $config['models'];

        $this->assertModelProvidersExist($models, $providers);

        foreach ($providers as $name => $provider) {
            $definition = new Definition(ProviderDefinition::class, [
                $name,
                $provider['type'],
                $provider['base_url'],
                $provider['api_key'],
                $provider['timeout'],
            ]);
            $definition->setPublic(false);
            $definition->addTag(self::PROVIDER_TAG);

            $container->setDefinition('golden_gate.provider.' . $name, $definition);
        }

        $container->setParameter(self::MODELS_PARAMETER, $models);
    }

    public function getAlias(): string
    {
        return 'golden_gate';
    }

    /**
     * @param array<string, ModelConfig>    $models
     * @param array<string, ProviderConfig> $providers
     */
    private function assertModelProvidersExist(array $models, array $providers): void
    {
        foreach ($models as $alias => $model) {
            if (!isset($providers[$model['provider']])) {
                throw new InvalidConfigurationException(\sprintf(
                    'golden_gate.models.%s references the unknown provider "%s". Declared providers: %s.',
                    $alias,
                    $model['provider'],
                    [] === $providers ? '(none)' : implode(', ', array_keys($providers)),
                ));
            }
        }
    }
}
