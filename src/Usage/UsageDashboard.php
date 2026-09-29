<?php

namespace App\Usage;

use App\Entity\UsageRecord;
use App\Repository\UsageRecordRepository;

/**
 * Read model behind the administration dashboard: what the gateway consumed for the
 * current calendar month, plus the most recent requests.
 *
 * It only ever reads metadata - prompts and completions are not stored, so they
 * cannot be exposed here.
 */
final class UsageDashboard
{
    public const RECENT_REQUESTS_LIMIT = 20;

    public function __construct(
        private readonly UsageRecordRepository $usageRecords,
        private readonly UsageLimitChecker $limitChecker,
    ) {
    }

    /**
     * @return array{
     *     periodStart: string,
     *     requests: int,
     *     tokens: int,
     *     estimatedCostUsd: float,
     *     successCount: int,
     *     errorCount: int,
     *     byModel: list<array<string, mixed>>,
     *     recentRequests: list<array<string, mixed>>,
     * }
     */
    public function overview(): array
    {
        $periodStart = $this->limitChecker->startOfCurrentMonth();

        $consumption = $this->usageRecords->consumptionSince($periodStart);
        $byStatus = $this->usageRecords->countByStatusSince($periodStart);

        $byModel = [];
        foreach ($this->usageRecords->usageByModelSince($periodStart) as $row) {
            $byModel[] = [
                'model' => $row['modelAlias'],
                'provider' => $row['provider'],
                'requests' => $row['requests'],
                'tokens' => $row['tokens'],
                'estimatedCostUsd' => $row['costMicroUsd'] / 1_000_000,
            ];
        }

        return [
            'periodStart' => $periodStart->format(\DATE_ATOM),
            'requests' => $consumption->getRequests(),
            'tokens' => $consumption->getTokens(),
            'estimatedCostUsd' => $consumption->getCost()->toUsd(),
            'successCount' => $byStatus[UsageStatus::Success->value] ?? 0,
            'errorCount' => $byStatus[UsageStatus::Error->value] ?? 0,
            'byModel' => $byModel,
            'recentRequests' => array_map(
                $this->toRecentRequest(...),
                $this->usageRecords->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], self::RECENT_REQUESTS_LIMIT),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toRecentRequest(UsageRecord $record): array
    {
        return [
            'id' => $record->getId(),
            'requestId' => $record->getRequestId(),
            'createdAt' => $record->getCreatedAt()?->format(\DATE_ATOM),
            'apiKeyPrefix' => $record->getApiKeyPrefix(),
            'model' => $record->getModelAlias(),
            'provider' => $record->getProvider(),
            'status' => $record->getStatus()->value,
            'errorCategory' => $record->getErrorCategory(),
            'promptTokens' => $record->getPromptTokens(),
            'completionTokens' => $record->getCompletionTokens(),
            'totalTokens' => $record->getTotalTokens(),
            'estimatedCostUsd' => $record->getEstimatedCostUsd(),
            'latencyMs' => $record->getLatencyMs(),
        ];
    }
}
