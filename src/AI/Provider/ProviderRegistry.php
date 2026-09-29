<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderNotFoundException;

/**
 * Look-up of the providers declared in the `golden_gate.providers` configuration.
 *
 * Provider definitions carry credentials, so they are never exposed through the
 * admin API: only their names and base URLs are ever read outside of this layer.
 */
final class ProviderRegistry
{
    /**
     * @var array<string, ProviderDefinition>
     */
    private array $providers = [];

    /**
     * @param iterable<ProviderDefinition> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    /**
     * @throws ProviderNotFoundException
     */
    public function get(string $name): ProviderDefinition
    {
        return $this->providers[$name] ?? throw new ProviderNotFoundException(\sprintf(
            'Provider "%s" is not configured.',
            $name,
        ));
    }
}
