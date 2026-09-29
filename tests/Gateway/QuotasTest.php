<?php

namespace App\Tests\Gateway;

use App\Gateway\Http\GatewayRateLimiter;

/**
 * Functional tests of the monthly limits and the per-key rate limit.
 *
 * The gateway cannot know the token cost of a request before running it, so a limit
 * is enforced on what has already been consumed: the request that pushes a counter
 * past a ceiling is the last one served. That overshoot is asserted here so the
 * documented behaviour cannot regress silently.
 */
final class QuotasTest extends AbstractGatewayTestCase
{
    private const REQUEST_BODY = [
        'model' => 'mistral-small',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
    ];

    private function call(string $secret): void
    {
        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);
    }

    public function testTheMonthlyRequestLimitBlocksFurtherCalls(): void
    {
        $secret = $this->createApiKey(['monthlyRequestLimit' => 1]);
        $this->mockProviderResponse(self::chatCompletionPayload());

        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);

        $this->call($secret);
        $this->assertResponseStatusCodeSame(429);
        $this->assertJsonContains([
            'error' => ['type' => 'quota_error', 'code' => 'monthly_request_limit_exceeded'],
        ]);

        $this->assertCount(1, $this->upstreamRequests(), 'A refused request must not reach the provider.');
    }

    public function testTheMonthlyTokenLimitBlocksFurtherCalls(): void
    {
        $secret = $this->createApiKey(['monthlyTokenLimit' => 1_000]);
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 900, completionTokens: 600));

        // The first request is served: 0 tokens were consumed when it started. It
        // overshoots the limit by its own 1,500 tokens, which is the documented
        // one-request overshoot of the MVP.
        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);

        $this->call($secret);
        $this->assertResponseStatusCodeSame(429);
        $this->assertJsonContains([
            'error' => ['type' => 'quota_error', 'code' => 'monthly_token_limit_exceeded'],
        ]);

        $this->assertCount(1, $this->upstreamRequests());
    }

    public function testAModelWithoutTokenLimitIsNotBlockedByTokens(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 500_000, completionTokens: 500_000));

        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testTheMonthlyBudgetBlocksFurtherCalls(): void
    {
        // 0.10 USD per million input tokens and 0.30 per million output tokens.
        // One request of 1,000 + 500 tokens costs 250 micro-USD = 0.00025 USD.
        $secret = $this->createApiKey(['monthlyBudgetUsd' => 0.0002]);
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 1_000, completionTokens: 500));

        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);

        $this->call($secret);
        $this->assertResponseStatusCodeSame(429);
        $this->assertJsonContains([
            'error' => ['type' => 'quota_error', 'code' => 'monthly_budget_exceeded'],
        ]);

        $this->assertCount(1, $this->upstreamRequests());
    }

    public function testABudgetAboveTheConsumptionDoesNotBlock(): void
    {
        $secret = $this->createApiKey(['monthlyBudgetUsd' => 5]);
        $this->mockProviderResponse(self::chatCompletionPayload());

        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testLimitsOnlyCountTheCurrentMonth(): void
    {
        $secret = $this->createApiKey(['monthlyRequestLimit' => 1]);
        $this->mockProviderResponse(self::chatCompletionPayload());
        $this->mockProviderResponse(self::chatCompletionPayload());

        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);

        // Age the existing record out of the current calendar month.
        $this->ageEveryUsageRecord();

        $this->call($secret);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testThePerKeyRateLimitRejectsABurst(): void
    {
        $secret = $this->createApiKey();
        $prefix = substr($secret, 0, 15);
        self::getContainer()->get(GatewayRateLimiter::class)->reset($prefix);

        for ($i = 0; $i < 5; ++$i) {
            $this->mockProviderResponse(self::chatCompletionPayload());
        }

        for ($i = 0; $i < 5; ++$i) {
            $this->call($secret);
            $this->assertResponseStatusCodeSame(200, 'Request #' . ($i + 1) . ' is within the rate limit.');
        }

        $this->call($secret);
        $this->assertResponseStatusCodeSame(429);
        $this->assertJsonContains(['error' => ['type' => 'quota_error', 'code' => 'rate_limit_exceeded']]);

        $this->assertCount(5, $this->upstreamRequests(), 'The throttled request must not reach the provider.');
    }

    private function ageEveryUsageRecord(): void
    {
        $connection = self::getContainer()->get('doctrine')->getConnection();
        $connection->executeStatement(
            'UPDATE usage_record SET created_at = :past',
            ['past' => new \DateTimeImmutable('first day of last month')->format('Y-m-d H:i:s')],
        );
    }
}
