<?php

namespace App\AI\Provider;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericPlatformFactory;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralPlatformFactory;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the Symfony AI platform (and the model instance) matching a provider definition.
 *
 * This is the only place that knows about provider types: the rest of the gateway
 * manipulates ProviderDefinition, ModelDefinition and the normalized DTOs.
 *
 * Platforms are memoized because building one creates an HTTP-backed provider, and a
 * gateway request should never build the same provider twice.
 */
final class PlatformFactory
{
    /**
     * @var array<string, PlatformInterface>
     */
    private array $platforms = [];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function createPlatform(ProviderDefinition $provider): PlatformInterface
    {
        return $this->platforms[$provider->getName()] ??= $this->buildPlatform($provider);
    }

    /**
     * The upstream model, typed for the bridge that will send the request: each
     * Symfony AI model client only accepts the model class of its own bridge.
     */
    public function createModel(ProviderDefinition $provider, string $upstreamModel): Model
    {
        return match ($provider->getType()) {
            ProviderDefinition::TYPE_MISTRAL => new Mistral($upstreamModel),
            default => new CompletionsModel($upstreamModel),
        };
    }

    private function buildPlatform(ProviderDefinition $provider): PlatformInterface
    {
        $httpClient = $this->httpClient->withOptions([
            'timeout' => $provider->getTimeout(),
            'max_duration' => $provider->getTimeout() + 5,
        ]);

        return match ($provider->getType()) {
            ProviderDefinition::TYPE_MISTRAL => MistralPlatformFactory::createPlatform(
                apiKey: $provider->getApiKey(),
                httpClient: $httpClient,
                name: $provider->getName(),
                baseUrl: $provider->getBaseUrl(),
            ),
            default => GenericPlatformFactory::createPlatform(
                baseUrl: $provider->getBaseUrl(),
                apiKey: '' === $provider->getApiKey() ? null : $provider->getApiKey(),
                httpClient: $httpClient,
                name: $provider->getName(),
            ),
        };
    }
}
