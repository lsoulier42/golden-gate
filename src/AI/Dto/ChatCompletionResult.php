<?php

namespace App\AI\Dto;

use App\AI\Model\ModelDefinition;

/**
 * Normalized result of a provider call, whatever the provider returned.
 *
 * It carries everything the usage record needs, plus the cost estimated from the
 * prices configured on the resolved model. Prompt and completion contents are
 * available to the caller but are never persisted.
 */
final class ChatCompletionResult
{
    public function __construct(
        private readonly string $id,
        private readonly ModelDefinition $model,
        private readonly string $content,
        private readonly ?string $finishReason,
        private readonly ChatCompletionUsage $usage,
        private readonly int $latencyMs,
        private readonly ?string $providerRequestId = null,
        private readonly ?string $providerModel = null,
    ) {
    }

    /**
     * Gateway-level identifier of this completion.
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function getModel(): ModelDefinition
    {
        return $this->model;
    }

    /**
     * Public alias the caller asked for.
     */
    public function getModelAlias(): string
    {
        return $this->model->getAlias();
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    public function getUsage(): ChatCompletionUsage
    {
        return $this->usage;
    }

    /**
     * Provider-side request identifier, when the provider exposes one. Logged for
     * support purposes; never returned to the calling application.
     */
    public function getProviderRequestId(): ?string
    {
        return $this->providerRequestId;
    }

    /**
     * Model name as reported by the provider, which may be a dated snapshot of the
     * upstream model that was requested.
     */
    public function getProviderModel(): ?string
    {
        return $this->providerModel;
    }

    public function getLatencyMs(): int
    {
        return $this->latencyMs;
    }

    public function getEstimatedCostMicroUsd(): int
    {
        return $this->model->estimateCostMicroUsd(
            $this->usage->getPromptTokens() ?? 0,
            $this->usage->getCompletionTokens() ?? 0,
        );
    }
}
