<?php

namespace App\Usage;

use App\Money\UsdAmount;

/**
 * What one API key (or the whole gateway) consumed over the current calendar month.
 *
 * Amounts are integer micro-USD all the way from the database, so comparing them to
 * a configured limit is exact.
 */
final class MonthlyConsumption
{
    public function __construct(
        private readonly int $requests,
        private readonly int $tokens,
        private readonly int $costMicroUsd,
    ) {
    }

    public function getRequests(): int
    {
        return $this->requests;
    }

    public function getTokens(): int
    {
        return $this->tokens;
    }

    public function getCost(): UsdAmount
    {
        return UsdAmount::fromMicroUsd($this->costMicroUsd);
    }

    public function getCostMicroUsd(): int
    {
        return $this->costMicroUsd;
    }
}
