<?php

namespace App\Tests\Gateway;

/**
 * Functional tests of POST /v1/chat/completions and GET /v1/models.
 *
 * Provider calls are always mocked: the suite never reaches a real LLM API.
 */
final class ChatCompletionsTest extends AbstractGatewayTestCase
{
    private const COMPLETION_BODY = [
        'model' => 'mistral-small',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ],
        'temperature' => 0.7,
        'max_tokens' => 500,
    ];

    public function testAGatewayRequestWithoutAnyCredentialIsRejected(): void
    {
        static::createClient()->request('POST', '/v1/chat/completions', ['json' => self::COMPLETION_BODY]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
            ],
        ]);
    }

    public function testAGatewayRequestWithAMalformedKeyIsRejected(): void
    {
        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders('not-a-golden-gate-key'),
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'invalid_api_key']]);
        $this->assertSame([], $this->upstreamRequests(), 'The provider must not be called.');
    }

    public function testAnUnknownKeySharingAnExistingPrefixIsRejected(): void
    {
        $secret = $this->createApiKey();
        $forged = substr($secret, 0, 15) . str_repeat('a', strlen($secret) - 15);

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($forged),
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'invalid_api_key']]);
    }

    public function testADisabledKeyIsRejectedWithItsOwnErrorCode(): void
    {
        $secret = $this->createApiKey(['enabled' => false]);

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'api_key_disabled']]);
    }

    public function testAnExpiredKeyIsRejectedWithItsOwnErrorCode(): void
    {
        $secret = $this->createApiKey(['expiresAt' => new \DateTimeImmutable('-1 hour')]);

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'api_key_expired']]);
    }

    public function testAnAdministrationJwtIsNotAcceptedAsAGatewayCredential(): void
    {
        // A structurally valid JWT (three segments) must not pass for an API key.
        $jwt = 'eyJ0eXAiOiJKV1QifQ.eyJyb2xlcyI6WyJST0xFX0FETUlOIl19.signature';

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($jwt),
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'invalid_api_key']]);
    }

    public function testAValidRequestIsProxiedAndReturnsAnOpenAiStyleResponse(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload());

        $response = static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains([
            'object' => 'chat.completion',
            'model' => 'mistral-small',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello from the provider!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1_000,
                'completion_tokens' => 500,
                'total_tokens' => 1_500,
            ],
        ]);

        $payload = $response->toArray();
        $this->assertStringStartsWith('chatcmpl-', (string) $payload['id']);

        // The public alias is what the client asked for; the upstream identifier
        // stays inside the gateway.
        $this->assertSame('mistral-small', $payload['model']);
        $this->assertStringNotContainsString('ovh.example.com', json_encode($payload, \JSON_THROW_ON_ERROR));

        $this->assertCount(1, $this->upstreamRequests());
        $this->assertSame($this->providerUrl('OVHCLOUD_BASE_URL'), $this->upstreamRequests()[0]['url']);
        $this->assertSame('Mistral-Small-3.2-24B-Instruct-2506', $this->upstreamRequests()[0]['body']['model']);
        $this->assertSame(0.7, $this->upstreamRequests()[0]['body']['temperature']);
        $this->assertSame(500, $this->upstreamRequests()[0]['body']['max_tokens']);
        $this->assertSame(
            [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
            $this->upstreamRequests()[0]['body']['messages'],
        );
    }

    public function testASecondProviderIsReachableThroughTheSameEndpointContract(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload(model: 'mistral-medium-2508'));

        $response = static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-medium', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(200);

        // Same response shape, different upstream provider: this is the whole point
        // of the gateway, and the client only changed the model alias.
        $this->assertJsonContains([
            'object' => 'chat.completion',
            'model' => 'mistral-medium',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello from the provider!'],
            ]],
        ]);
        $this->assertCount(1, $this->upstreamRequests());
        $this->assertSame($this->providerUrl('MISTRAL_BASE_URL'), $this->upstreamRequests()[0]['url']);
        $this->assertSame('mistral-medium-latest', $this->upstreamRequests()[0]['body']['model']);
    }

    public function testAnUnknownModelIsRejectedWithModelNotFound(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload());

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonContains(['error' => ['type' => 'invalid_request_error', 'code' => 'model_not_found']]);
        $this->assertSame([], $this->upstreamRequests());
    }

    public function testAModelOutsideTheAllowListIsForbidden(): void
    {
        $secret = $this->createApiKey(['allowedModels' => ['qwen-32b']]);
        $this->mockProviderResponse(self::chatCompletionPayload());

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertJsonContains(['error' => ['type' => 'permission_error', 'code' => 'model_not_allowed']]);
        $this->assertSame([], $this->upstreamRequests());
    }

    public function testAModelInsideTheAllowListIsServed(): void
    {
        $secret = $this->createApiKey(['allowedModels' => ['mistral-small']]);
        $this->mockProviderResponse(self::chatCompletionPayload());

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(200);
    }

    public function testAnInvalidPayloadIsRejectedBeforeCallingTheProvider(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(self::chatCompletionPayload());

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-small', 'messages' => []],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonContains(['error' => ['type' => 'invalid_request_error']]);
        $this->assertSame([], $this->upstreamRequests());
    }

    public function testAnUnsupportedMessageRoleIsRejected(): void
    {
        $secret = $this->createApiKey();

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => ['model' => 'mistral-small', 'messages' => [['role' => 'robot', 'content' => 'Hello']]],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonContains(['error' => ['code' => 'invalid_request_error']]);
    }

    public function testATemperatureOutOfRangeIsRejected(): void
    {
        $secret = $this->createApiKey();

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => [
                'model' => 'mistral-small',
                'messages' => [['role' => 'user', 'content' => 'Hello']],
                'temperature' => 5,
            ],
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonContains(['error' => ['code' => 'invalid_request_error']]);
    }

    public function testAProviderFailureIsNormalizedWithoutLeakingTheCredential(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderResponse(
            ['error' => ['message' => 'Upstream is unavailable, request id 42']],
            503,
        );

        $response = static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(502);
        $this->assertJsonContains(['error' => ['type' => 'provider_error', 'code' => 'provider_error']]);

        $content = $response->getContent(false);
        $this->assertStringNotContainsString('ovhcloud-key', $content);
        $this->assertStringNotContainsString($secret, $content);
    }

    public function testAProviderTimeoutIsReportedAsSuch(): void
    {
        $secret = $this->createApiKey();
        $this->mockProviderTransportFailure('Idle timeout reached for "https://ovh.example.com/v1/chat/completions".');

        static::createClient()->request('POST', '/v1/chat/completions', [
            'json' => self::COMPLETION_BODY,
            'headers' => $this->gatewayHeaders($secret),
        ]);

        $this->assertResponseStatusCodeSame(504);
        $this->assertJsonContains(['error' => ['type' => 'provider_error', 'code' => 'provider_timeout']]);
    }

    public function testModelsEndpointOnlyListsWhatTheKeyMayUse(): void
    {
        $secret = $this->createApiKey(['allowedModels' => ['mistral-small']]);

        $response = static::createClient()->request('GET', '/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $secret],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $payload = $response->toArray();
        $this->assertSame('list', $payload['object']);

        $ids = array_column($payload['data'], 'id');
        $this->assertSame(['mistral-small'], $ids);
    }

    public function testModelsEndpointListsEveryEnabledModelForAnUnrestrictedKey(): void
    {
        $secret = $this->createApiKey();

        $response = static::createClient()->request('GET', '/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $secret],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $ids = array_column($response->toArray()['data'], 'id');
        $this->assertSame(['mistral-small', 'qwen-32b', 'mistral-medium'], $ids);

        // Never expose upstream identifiers or credentials on the public endpoint.
        $this->assertStringNotContainsString('api_key', (string) $response->getContent());
        $this->assertStringNotContainsString('ovhcloud-key', (string) $response->getContent());
    }

    public function testModelsEndpointRequiresAKey(): void
    {
        static::createClient()->request('GET', '/v1/models');

        $this->assertResponseStatusCodeSame(401);
        $this->assertJsonContains(['error' => ['code' => 'invalid_api_key']]);
    }
}
