<?php

namespace App\Gateway\Http;

use App\Gateway\Error\GatewayErrorCode;
use App\Gateway\Error\GatewayException;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Per-API-key rate limiting: the abuse guard that protects the gateway (and the
 * upstream provider credits) from a runaway client.
 *
 * It is deliberately independent from the monthly quotas: this one is about burst
 * rate, the quotas are about consumption.
 */
final class GatewayRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $gatewayApiKeyLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws GatewayException when the key exceeded its request rate
     */
    public function assertNotRateLimited(string $apiKeyPrefix): void
    {
        $limiter = $this->limiterFor($apiKeyPrefix);
        $limit = $limiter->consume();

        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
        $this->logger->warning('Gateway request rejected: rate limit exceeded.', [
            'api_key_prefix' => $apiKeyPrefix,
            'retry_after_seconds' => max(0, $retryAfter),
        ]);

        throw new GatewayException(
            GatewayErrorCode::RateLimitExceeded,
            \sprintf('Rate limit exceeded. Retry in %d seconds.', max(1, $retryAfter)),
        );
    }

    /**
     * Resets the counter of a key; used by tests that must start from a clean slate.
     */
    public function reset(string $apiKeyPrefix): void
    {
        $this->limiterFor($apiKeyPrefix)->reset();
    }

    private function limiterFor(string $apiKeyPrefix): LimiterInterface
    {
        return $this->gatewayApiKeyLimiter->create($apiKeyPrefix);
    }
}
