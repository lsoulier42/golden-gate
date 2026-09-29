<?php

namespace App\Tests\Gateway;

use App\Entity\UsageRecord;
use App\Repository\ApiKeyRepository;
use App\Repository\UsageRecordRepository;
use App\Usage\UsageStatus;

/**
 * Functional tests of the usage and cost tracking: every attempted provider call
 * must leave exactly one immutable record, and no prompt content may ever be stored.
 */
final class UsageRecordingTest extends AbstractGatewayTestCase
{
    private const REQUEST_BODY = [
        'model' => 'mistral-small',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a confidential system prompt.'],
            ['role' => 'user', 'content' => 'My secret question.'],
        ],
        'temperature' => 0.7,
        'max_tokens' => 500,
    ];

    public function testASuccessfulRequestIsRecordedWithTokensCostAndLatency(): void
    {
        $secret = $this->createApiKey(['name' => 'Reporting key']);
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 1_000, completionTokens: 500));

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $record = $this->onlyUsageRecord();

        $this->assertSame(UsageStatus::Success, $record->getStatus());
        $this->assertSame('mistral-small', $record->getModelAlias());
        $this->assertSame('ovhcloud', $record->getProvider());
        $this->assertSame('Mistral-Small-3.2-24B-Instruct-2506', $record->getUpstreamModel());
        $this->assertSame(1_000, $record->getPromptTokens());
        $this->assertSame(500, $record->getCompletionTokens());
        $this->assertSame(1_500, $record->getTotalTokens());
        $this->assertSame('provider-request-1', $record->getProviderRequestId());
        $this->assertNull($record->getErrorCategory());
        $this->assertNotNull($record->getLatencyMs());
        $this->assertGreaterThanOrEqual(0, $record->getLatencyMs());

        // 1,000 input tokens at 0.10 USD/M + 500 output tokens at 0.30 USD/M.
        $this->assertSame(250, $record->getEstimatedCost()->toMicroUsd());
        $this->assertSame(0.00025, $record->getEstimatedCostUsd());
        $this->assertSame('chatcmpl-', substr($record->getRequestId(), 0, 9));
        $this->assertSame('gg_' . substr($secret, 3, 12), $record->getApiKeyPrefix());
    }

    public function testTheRecordNeverContainsPromptOrCompletionContent(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload(content: 'A confidential completion.'));

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(200);

        // The whole persisted row, serialized, must not carry any prompt content.
        $row = $this->rawUsageRow();
        $serialized = json_encode($row, \JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('confidential system prompt', $serialized);
        $this->assertStringNotContainsString('My secret question', $serialized);
        $this->assertStringNotContainsString('A confidential completion', $serialized);
        $this->assertArrayNotHasKey('prompt', $row);
        $this->assertArrayNotHasKey('completion', $row);
    }

    public function testAProviderFailureIsRecordedWithItsErrorCategory(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(['error' => ['message' => 'Provider is overloaded']], 500);

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(502);

        $record = $this->onlyUsageRecord();

        $this->assertSame(UsageStatus::Error, $record->getStatus());
        $this->assertSame('provider_server_error', $record->getErrorCategory());
        $this->assertSame(0, $record->getEstimatedCost()->toMicroUsd());
        // No answer means no token accounting, which is reported as null rather than 0.
        $this->assertNull($record->getPromptTokens());
        $this->assertNull($record->getTotalTokens());
    }

    public function testATimeoutIsRecordedAsSuch(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderTransportFailure('Idle timeout reached for the provider.');

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(504);
        $this->assertSame('provider_timeout', $this->onlyUsageRecord()->getErrorCategory());
    }

    public function testARequestRefusedByAQuotaIsNotRecordedAsUsage(): void
    {
        $secret = $this->createApiKey(['monthlyRequestLimit' => 1]);
        $this->mockProviderResponse(self::chatCompletionPayload());

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);
        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(429);

        // Only the served request consumed quota; the refused one is logged, not billed.
        $this->assertCount(1, $this->usageRecords());
    }

    public function testTheApiKeyLastUsedAtIsRefreshed(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload());

        $this->assertNull($this->apiKey()->getLastUsedAt());

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::REQUEST_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $repository = self::getContainer()->get(ApiKeyRepository::class);
        $repository->getEntityManager()->clear();

        $this->assertNotNull($repository->findOneBy(['keyPrefix' => 'gg_' . substr($secret, 3, 12)])?->getLastUsedAt());
    }

    public function testUsageIsAggregatedForTheCurrentMonth(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 1_000, completionTokens: 500));
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 2_000, completionTokens: 1_000));

        foreach ([0, 1] as $ignored) {
            static::createClient()->request('POST', '/v1/chat/completions', [
                'json' => self::REQUEST_BODY,
                'headers' => $this->gatewayHeaders($secret),
            ]);
        }

        $this->assertResponseStatusCodeSame(200);

        $repository = self::getContainer()->get(UsageRecordRepository::class);
        $consumption = $repository->monthlyConsumption(
            $this->apiKey(),
            new \DateTimeImmutable('first day of this month 00:00:00'),
        );

        $this->assertSame(2, $consumption->getRequests());
        $this->assertSame(4_500, $consumption->getTokens());
        // 3,000 input tokens at 0.10 USD/M + 1,500 output tokens at 0.30 USD/M.
        $this->assertSame(750, $consumption->getCostMicroUsd());
    }

    private function apiKey(): \App\Entity\ApiKey
    {
        $repository = self::getContainer()->get(ApiKeyRepository::class);
        $key = $repository->findOneBy([]);

        $this->assertInstanceOf(\App\Entity\ApiKey::class, $key);

        return $key;
    }

    /**
     * @return list<UsageRecord>
     */
    private function usageRecords(): array
    {
        return self::getContainer()->get(UsageRecordRepository::class)->findBy([], ['id' => 'ASC']);
    }

    private function onlyUsageRecord(): UsageRecord
    {
        $records = $this->usageRecords();

        $this->assertCount(1, $records, 'Exactly one usage record is expected.');

        return $records[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawUsageRow(): array
    {
        return (array) self::getContainer()->get('doctrine')->getConnection()
            ->fetchAssociative('SELECT * FROM usage_record');
    }
}
