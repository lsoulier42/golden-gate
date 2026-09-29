<?php

namespace App\AI\Model;

use App\AI\Exception\InvalidModelConfigurationException;

/**
 * One public model exposed by Golden Gate: a stable alias resolved to a provider
 * and an upstream model identifier, with the prices used to estimate request cost.
 *
 * Prices are configured in USD per million tokens and immediately converted to
 * integer micro-USD per million tokens, so cost estimates never rely on floats.
 */
final class ModelDefinition
{
    private const MICRO_USD_PER_USD = 1_000_000;

    private readonly string $alias;
    private readonly string $provider;
    private readonly string $upstreamModel;
    private readonly bool $enabled;
    private readonly int $inputMicroUsdPerMillionTokens;
    private readonly int $outputMicroUsdPerMillionTokens;
    private readonly string $description;
    private readonly ?int $contextWindow;

    public function __construct(
        string $alias,
        string $provider,
        string $upstreamModel,
        bool $enabled = true,
        float $inputPricePerMillionUsd = 0.0,
        float $outputPricePerMillionUsd = 0.0,
        string $description = '',
        ?int $contextWindow = null,
    ) {
        if ('' === trim($alias)) {
            throw new InvalidModelConfigurationException('A model alias cannot be empty.');
        }

        if ('' === trim($provider)) {
            throw new InvalidModelConfigurationException(\sprintf('Model "%s" has no provider.', $alias));
        }

        if ('' === trim($upstreamModel)) {
            throw new InvalidModelConfigurationException(\sprintf('Model "%s" has no upstream model.', $alias));
        }

        $this->alias = $alias;
        $this->provider = $provider;
        $this->upstreamModel = $upstreamModel;
        $this->enabled = $enabled;
        $this->inputMicroUsdPerMillionTokens = self::toMicroUsdPerMillionTokens($inputPricePerMillionUsd, $alias);
        $this->outputMicroUsdPerMillionTokens = self::toMicroUsdPerMillionTokens($outputPricePerMillionUsd, $alias);
        $this->description = $description;
        $this->contextWindow = $contextWindow;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getUpstreamModel(): string
    {
        return $this->upstreamModel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getInputPricePerMillionUsd(): float
    {
        return $this->inputMicroUsdPerMillionTokens / self::MICRO_USD_PER_USD;
    }

    public function getOutputPricePerMillionUsd(): float
    {
        return $this->outputMicroUsdPerMillionTokens / self::MICRO_USD_PER_USD;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getContextWindow(): ?int
    {
        return $this->contextWindow;
    }

    /**
     * Estimated price of a request, in micro-USD (1 micro-USD = 0.000001 USD),
     * computed with integers only so the amount stays exact.
     */
    public function estimateCostMicroUsd(int $inputTokens, int $outputTokens): int
    {
        $numerator = $inputTokens * $this->inputMicroUsdPerMillionTokens
            + $outputTokens * $this->outputMicroUsdPerMillionTokens;

        return intdiv($numerator + 500_000, self::MICRO_USD_PER_USD);
    }

    private static function toMicroUsdPerMillionTokens(float $pricePerMillionUsd, string $alias): int
    {
        if ($pricePerMillionUsd < 0) {
            throw new InvalidModelConfigurationException(\sprintf('Model "%s" has a negative price.', $alias));
        }

        return (int) round($pricePerMillionUsd * self::MICRO_USD_PER_USD);
    }
}
