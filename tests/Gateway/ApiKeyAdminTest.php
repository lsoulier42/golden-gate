<?php

namespace App\Tests\Gateway;

use App\ApiKey\ApiKeyGenerator;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Tests\Factory\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional tests of the administration surface of the gateway.
 *
 * The security property under test is the one-time secret: the clear-text key is
 * returned by the creation endpoint, and no other endpoint can ever reveal it.
 */
final class ApiKeyAdminTest extends AbstractGatewayTestCase
{
    private const ADMIN_PASSWORD = 'secret';

    public function testAdminEndpointsRequireAnAdministrator(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/gateway/api-keys');
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/gateway/api-keys', ['json' => ['name' => 'Anonymous']]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testARegularUserCannotManageApiKeys(): void
    {
        $token = $this->tokenFor('ROLE_USER');

        static::createClient()->request('GET', '/api/gateway/api-keys', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testApiKeysAreNeverAcceptedOnTheAdministrationApi(): void
    {
        $secret = $this->createApiKey();

        static::createClient()->request('GET', '/api/gateway/api-keys', [
            'headers' => ['Authorization' => 'Bearer ' . $secret],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAnAdministratorCreatesAKeyAndSeesTheSecretExactlyOnce(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');

        $created = static::createClient()->request('POST', '/api/gateway/api-keys', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => [
                'name' => 'Reporting job',
                'monthlyRequestLimit' => 1_000,
                'monthlyTokenLimit' => 2_000_000,
                'monthlyBudgetUsd' => 5.5,
                'allowedModels' => ['mistral-small'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $payload = $created->toArray();
        $secret = $payload['key'];

        $this->assertIsString($secret);
        $this->assertStringStartsWith('gg_', $secret);
        $this->assertSame('Reporting job', $payload['name']);
        $this->assertSame(1_000, $payload['monthlyRequestLimit']);
        $this->assertSame(2_000_000, $payload['monthlyTokenLimit']);
        $this->assertSame(5.5, $payload['monthlyBudgetUsd']);
        $this->assertSame(['mistral-small'], $payload['allowedModels']);
        $this->assertTrue($payload['enabled']);
        $this->assertSame('gg_' . substr($secret, 3, 12), $payload['keyPrefix']);

        // The secret works as a gateway credential...
        $this->mockProviderResponse(self::chatCompletionPayload());
        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-small', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);
        $this->assertResponseStatusCodeSame(200);

        // ... and is not part of the administration representation.
        $listed = static::createClient()->request('GET', '/api/gateway/api-keys', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $body = (string) $listed->getContent();
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('keyHash', $body);

        $member = $listed->toArray()['member'][0];
        $this->assertArrayNotHasKey('key', $member);
        $this->assertArrayNotHasKey('keyHash', $member);
    }

    public function testTheStoredSecretIsOnlyAHash(): void
    {
        $this->createApiKey();

        /** @var ApiKey $apiKey */
        $apiKey = self::getContainer()->get(ApiKeyRepository::class)->findOneBy([]);

        $this->assertSame(64, strlen($apiKey->getKeyHash()));
        $this->assertStringNotContainsString($apiKey->getKeyHash(), $apiKey->getKeyPrefix());

        $generator = self::getContainer()->get(ApiKeyGenerator::class);
        $this->assertFalse($generator->looksLikeApiKey($apiKey->getKeyHash()));
    }

    public function testAnAdministratorUpdatesTheLimitsOfAKey(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');
        $this->createApiKey(['name' => 'Initial name']);
        $apiKey = self::getContainer()->get(ApiKeyRepository::class)->findOneBy([]);

        $response = static::createClient()->request('PATCH', '/api/gateway/api-keys/' . $apiKey?->getId(), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/merge-patch+json',
            ],
            'json' => [
                'name' => 'Renamed key',
                'monthlyRequestLimit' => 42,
                'monthlyBudgetUsd' => 12.25,
                'allowedModels' => ['qwen-32b', 'mistral-small'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains([
            'name' => 'Renamed key',
            'monthlyRequestLimit' => 42,
            'monthlyBudgetUsd' => 12.25,
            // Stored sorted, so the UI always shows the same order.
            'allowedModels' => ['mistral-small', 'qwen-32b'],
        ]);
    }

    public function testAnAdministratorDisablesAndRevokesAKey(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');
        $secret = $this->createApiKey();
        $apiKey = self::getContainer()->get(ApiKeyRepository::class)->findOneBy([]);

        static::createClient()->request('PATCH', '/api/gateway/api-keys/' . $apiKey?->getId(), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/merge-patch+json',
            ],
            'json' => ['enabled' => false],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['enabled' => false]);

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-small', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'api_key_disabled']]);

        static::createClient()->request('DELETE', '/api/gateway/api-keys/' . $apiKey?->getId(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertNull(self::getContainer()->get(ApiKeyRepository::class)->find($apiKey?->getId()));
    }

    public function testCreatingAKeyRequiresAName(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');

        static::createClient()->request('POST', '/api/gateway/api-keys', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => ['name' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreatingAKeyWithAnExpiryInThePastIsRejected(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');

        static::createClient()->request('POST', '/api/gateway/api-keys', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => ['name' => 'Too late', 'expiresAt' => '2020-01-01T00:00:00+00:00'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testTheDashboardReportsTheCurrentMonth(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 1_000, completionTokens: 500));

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-small', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $response = static::createClient()->request('GET', '/api/gateway/dashboard', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $payload = $response->toArray();
        $this->assertSame(1, $payload['requests']);
        $this->assertSame(1_500, $payload['tokens']);
        $this->assertSame(0.00025, $payload['estimatedCostUsd']);
        $this->assertSame(1, $payload['successCount']);
        $this->assertSame(0, $payload['errorCount']);
        $this->assertSame('mistral-small', $payload['byModel'][0]['model']);
        $this->assertSame('ovhcloud', $payload['byModel'][0]['provider']);
        $this->assertCount(1, $payload['recentRequests']);

        // Nothing that could carry client content is exposed.
        $this->assertArrayNotHasKey('prompt', $payload['recentRequests'][0]);
        $this->assertArrayNotHasKey('completion', $payload['recentRequests'][0]);
    }

    public function testTheModelRegistryIsExposedReadOnly(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');

        $response = static::createClient()->request('GET', '/api/gateway/models', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $models = $response->toArray()['models'];
        $aliases = array_column($models, 'alias');

        $this->assertSame(['mistral-small', 'qwen-32b', 'mistral-medium'], $aliases);
        $this->assertSame('ovhcloud', $models[0]['provider']);
        $this->assertSame('Mistral-Small-3.2-24B-Instruct-2506', $models[0]['upstreamModel']);
        $this->assertSame(0.1, $models[0]['inputPricePerMillionUsd']);

        // Provider credentials must never appear here.
        $this->assertStringNotContainsString('api_key', (string) $response->getContent());
    }

    public function testTheUsageTableIsPaginatedAndFilterable(): void
    {
        $token = $this->tokenFor('ROLE_ADMIN');
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload());
        $this->mockProviderResponse(self::chatCompletionPayload(promptTokens: 10, completionTokens: 5));

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-small', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);
        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-medium', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $headers = ['Authorization' => 'Bearer ' . $token];

        $all = static::createClient()->request('GET', '/api/gateway/usage', ['headers' => $headers]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(2, $all->toArray()['totalItems']);

        $filtered = static::createClient()->request('GET', '/api/gateway/usage?modelAlias=mistral-medium', [
            'headers' => $headers,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $filtered->toArray()['totalItems']);

        $byProvider = static::createClient()->request('GET', '/api/gateway/usage?provider=mistral', [
            'headers' => $headers,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $byProvider->toArray()['totalItems']);
    }

    private function tokenFor(string $role): string
    {
        $email = strtolower($role) . '@example.com';
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        UserFactory::createOne([
            'email' => $email,
            'password' => $hasher->hashPassword(new User(), self::ADMIN_PASSWORD),
            'roles' => [$role],
        ]);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => $email, 'password' => self::ADMIN_PASSWORD],
        ]);

        return $response->toArray()['token'];
    }
}
