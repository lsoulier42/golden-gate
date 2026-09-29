<?php

namespace App\Usage;

/**
 * Outcome of a gateway request, as stored on the usage record.
 */
enum UsageStatus: string
{
    case Success = 'success';
    case Error = 'error';

    /**
     * Whether the failure was caused by the caller rather than by the provider.
     * Provider failures still consumed a request attempt and are worth distinguishing
     * when an operator investigates a spike.
     */
    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Error => 'Error',
        };
    }
}
