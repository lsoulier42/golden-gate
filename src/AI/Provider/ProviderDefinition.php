<?php

namespace App\AI\Provider;

/**
 * Declarative description of one upstream LLM API.
 *
 * Instances are built by GoldenGateExtension from the `golden_gate.providers`
 * configuration, so the API key stays an environment placeholder resolved lazily
 * by the container instead of being embedded in the compiled container.
 */
final class ProviderDefinition
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_MISTRAL = 'mistral';

    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly float $timeout = 30.0,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Never log or expose this value: it is the upstream provider credential.
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }
}
