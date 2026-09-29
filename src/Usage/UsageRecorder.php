<?php

namespace App\Usage;

use App\AI\Dto\ChatCompletionResult;
use App\AI\Exception\ProviderCallException;
use App\AI\Model\ModelDefinition;
use App\Entity\ApiKey;
use App\Entity\UsageRecord;
use App\Money\UsdAmount;
use App\Repository\UsageRecordRepository;
use Psr\Log\LoggerInterface;

/**
 * Persists one immutable usage record per gateway request attempt, and emits the
 * structured log line operators use to follow the gateway.
 *
 * Never stored: provider credentials, clear-text Golden Gate keys, prompts and
 * completions. The log line carries identifiers and counters only.
 */
final class UsageRecorder
{
    public function __construct(
        private readonly UsageRecordRepository $usageRecords,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function recordSuccess(ApiKey $apiKey, ChatCompletionResult $result): UsageRecord
    {
        $record = $this->createRecord($apiKey, $result->getModel(), $result->getId());
        $record
            ->setStatus(UsageStatus::Success)
            ->setPromptTokens($result->getUsage()->getPromptTokens())
            ->setCompletionTokens($result->getUsage()->getCompletionTokens())
            ->setTotalTokens($result->getUsage()->getTotalTokens())
            ->setEstimatedCost(UsdAmount::fromMicroUsd($result->getEstimatedCostMicroUsd()))
            ->setLatencyMs($result->getLatencyMs())
            ->setProviderRequestId($result->getProviderRequestId())
            ->setProviderModel($result->getProviderModel());

        $this->usageRecords->createOrUpdate($record);

        $this->logger->info('Gateway request completed.', [
            'request_id' => $record->getRequestId(),
            'api_key_id' => $apiKey->getId(),
            'api_key_prefix' => $apiKey->getKeyPrefix(),
            'model' => $record->getModelAlias(),
            'provider' => $record->getProvider(),
            'upstream_model' => $record->getUpstreamModel(),
            'prompt_tokens' => $record->getPromptTokens(),
            'completion_tokens' => $record->getCompletionTokens(),
            'total_tokens' => $record->getTotalTokens(),
            'estimated_cost_usd' => $record->getEstimatedCost()->toDecimalString(6),
            'latency_ms' => $record->getLatencyMs(),
            'status' => UsageStatus::Success->value,
        ]);

        return $record;
    }

    public function recordFailure(
        ApiKey $apiKey,
        ModelDefinition $model,
        string $requestId,
        ProviderCallException $exception,
    ): UsageRecord {
        $record = $this->createRecord($apiKey, $model, $requestId);
        $record
            ->setStatus(UsageStatus::Error)
            ->setErrorCategory($exception->getErrorCategory());

        $this->usageRecords->createOrUpdate($record);

        $this->logger->warning('Gateway request failed at the provider.', [
            'request_id' => $record->getRequestId(),
            'api_key_id' => $apiKey->getId(),
            'api_key_prefix' => $apiKey->getKeyPrefix(),
            'model' => $record->getModelAlias(),
            'provider' => $record->getProvider(),
            'error_category' => $record->getErrorCategory(),
            'status' => UsageStatus::Error->value,
            // Raw provider wording, server-side only: it can mention the upstream
            // account but never the credential we sent it.
            'provider_detail' => $exception->getProviderDetail(),
        ]);

        return $record;
    }

    /**
     * A request refused before reaching the provider (quota, permissions) still
     * consumed nothing, so it is logged but not recorded as usage.
     *
     * @param array<string, mixed> $context
     */
    public function logRejection(ApiKey $apiKey, string $modelAlias, string $reason, array $context = []): void
    {
        $this->logger->warning('Gateway request rejected.', [
            'api_key_id' => $apiKey->getId(),
            'api_key_prefix' => $apiKey->getKeyPrefix(),
            'model' => $modelAlias,
            'reason' => $reason,
        ] + $context);
    }

    private function createRecord(ApiKey $apiKey, ModelDefinition $model, string $requestId): UsageRecord
    {
        return (new UsageRecord())
            ->setApiKey($apiKey)
            ->setApiKeyPrefix($apiKey->getKeyPrefix())
            ->setRequestId($requestId)
            ->setModelAlias($model->getAlias())
            ->setProvider($model->getProvider())
            ->setUpstreamModel($model->getUpstreamModel());
    }
}
