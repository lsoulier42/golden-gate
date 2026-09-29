<?php

namespace App\AI\Model;

use App\AI\Exception\ModelNotFoundException;

/**
 * Read model of the `golden_gate.models` configuration.
 *
 * The registry is configuration-driven on purpose: upstream identifiers and prices
 * stay in versioned files, and nothing about provider infrastructure is editable
 * from the administration UI.
 *
 * @phpstan-import-type ModelConfig from \App\DependencyInjection\Configuration
 */
final class ModelRegistry
{
    /**
     * @var array<string, ModelDefinition>
     */
    private array $models = [];

    /**
     * @param array<string, ModelConfig> $models
     */
    public function __construct(array $models)
    {
        foreach ($models as $alias => $model) {
            $this->models[$alias] = new ModelDefinition(
                alias: (string) $alias,
                provider: $model['provider'],
                upstreamModel: $model['upstream_model'],
                enabled: $model['enabled'] ?? true,
                inputPricePerMillionUsd: $model['input_price_per_million_usd'] ?? 0.0,
                outputPricePerMillionUsd: $model['output_price_per_million_usd'] ?? 0.0,
                description: $model['description'] ?? '',
                contextWindow: $model['context_window'] ?? null,
            );
        }
    }

    /**
     * The servable model behind a public alias.
     *
     * Disabled models are reported as unknown rather than as forbidden: from the
     * caller's point of view an alias the gateway does not serve simply does not exist.
     *
     * @throws ModelNotFoundException when the alias is unknown or the model is disabled
     */
    public function get(string $alias): ModelDefinition
    {
        $model = $this->find($alias);

        if (null === $model) {
            throw new ModelNotFoundException(\sprintf('Model "%s" does not exist.', $alias));
        }

        if (!$model->isEnabled()) {
            throw new ModelNotFoundException(\sprintf('Model "%s" does not exist.', $alias));
        }

        return $model;
    }

    /**
     * Resolves an alias to a disabled model as well: used by the admin screens,
     * where operators must see what is configured even when it is turned off.
     */
    public function find(string $alias): ?ModelDefinition
    {
        return $this->models[$alias] ?? null;
    }

    /**
     * Every declared model, disabled ones included.
     *
     * @return list<ModelDefinition>
     */
    public function all(): array
    {
        return array_values($this->models);
    }

    /**
     * The models the gateway may serve.
     *
     * @return list<ModelDefinition>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (ModelDefinition $model): bool => $model->isEnabled(),
        ));
    }
}
