<?php

namespace App\Usage;

use App\Entity\ApiKey;
use App\Gateway\Error\GatewayErrorCode;
use App\Gateway\Error\GatewayException;
use App\Money\UsdAmount;
use App\Repository\UsageRecordRepository;
use Psr\Clock\ClockInterface;

/**
 * Decides whether an API key may still consume the gateway, for the current calendar month.
 *
 * Deliberately simple for the MVP: a request is refused when what the key already
 * consumed has reached one of its limits. The provider is not called to find out
 * first, so a limit can be overshot by the tokens of at most one request - the one
 * that pushed the counter past the ceiling. Documented in the README.
 */
final class UsageLimitChecker
{
    public function __construct(
        private readonly UsageRecordRepository $usageRecords,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws GatewayException with a quota error code when a limit is already reached
     */
    public function assertWithinLimits(ApiKey $apiKey): void
    {
        $consumption = $this->consumptionOf($apiKey);

        if (null !== $limit = $apiKey->getMonthlyRequestLimit()) {
            if ($consumption->getRequests() >= $limit) {
                throw new GatewayException(
                    GatewayErrorCode::MonthlyRequestLimitExceeded,
                    \sprintf(
                        'Monthly request limit reached (%d of %d requests used this month).',
                        $consumption->getRequests(),
                        $limit,
                    ),
                );
            }
        }

        if (null !== $limit = $apiKey->getMonthlyTokenLimit()) {
            if ($consumption->getTokens() >= $limit) {
                throw new GatewayException(
                    GatewayErrorCode::MonthlyTokenLimitExceeded,
                    \sprintf(
                        'Monthly token limit reached (%d of %d tokens used this month).',
                        $consumption->getTokens(),
                        $limit,
                    ),
                );
            }
        }

        $budget = $apiKey->getMonthlyBudget();

        if (null !== $budget && $consumption->getCost()->isGreaterThanOrEqualTo($budget)) {
            throw new GatewayException(
                GatewayErrorCode::MonthlyBudgetExceeded,
                \sprintf(
                    'Monthly budget exhausted (%s of %s USD used this month).',
                    $consumption->getCost()->toDecimalString(4),
                    $budget->toDecimalString(4),
                ),
            );
        }
    }

    public function consumptionOf(ApiKey $apiKey): MonthlyConsumption
    {
        return $this->usageRecords->monthlyConsumption($apiKey, $this->startOfCurrentMonth());
    }

    /**
     * Calendar month boundaries in UTC: the gateway reports usage monthly, and a
     * single reference timezone keeps the counter stable for operators in any region.
     */
    public function startOfCurrentMonth(): \DateTimeImmutable
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('first day of this month')
            ->setTime(0, 0);
    }

    /**
     * Remaining budget of a key, or null when it has no budget.
     */
    public function remainingBudget(ApiKey $apiKey): ?UsdAmount
    {
        $budget = $apiKey->getMonthlyBudget();

        if (null === $budget) {
            return null;
        }

        return UsdAmount::fromMicroUsd($budget->toMicroUsd() - $this->consumptionOf($apiKey)->getCostMicroUsd());
    }
}
