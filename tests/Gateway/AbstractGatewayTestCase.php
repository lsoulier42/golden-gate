<?php

namespace App\Tests\Gateway;

use App\ApiKey\ApiKeyGenerator;
use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use App\Tests\AbstractApiTestCase;
use App\Tests\Http\ProviderMockResponseFactory;

/**
 * Base class for functional tests of the public gateway API.
 *
 * Provider calls are always mocked through ProviderMockResponseFactory: the suite
 * never reaches a real LLM API, and a test that forgets to queue an answer gets a
 * loud failure instead of a silent real call.
 */
abstract class AbstractGatewayTestCase extends AbstractApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->providerMock()->reset();
    }

    protected function providerMock(): ProviderMockResponseFactory
    {
        /** @var ProviderMockResponseFactory $factory */
        $factory = self::getContainer()->get(ProviderMockResponseFactory::class);

        return $factory;
    }

    /**
     * Queues the next provider answer.
     *
     * @param array<string, mixed> $payload
     */
    protected function mockProviderResponse(array $payload, int $statusCode = 200): void
    {
        $this->providerMock()->queueJson($payload, $statusCode);
    }

    /**
     * Queues a transport-level failure for the next provider call.
     */
    protected function mockProviderTransportFailure(string $message = 'Idle timeout reached for the provider.'): void
    {
        $this->providerMock()->queueTransportFailure($message);
    }

    /**
     * @return list<array{method: string, url: string, body: array<string, mixed>, headers: array<int, string>}>
     */
    protected function upstreamRequests(): array
    {
        return $this->providerMock()->requests();
    }

    /**
     * Creates an API key through the repository, and returns its clear-text secret.
     *
     * @param array{
     *     name?: string,
     *     monthlyRequestLimit?: int|null,
     *     monthlyTokenLimit?: int|null,
     *     monthlyBudgetUsd?: int|float|string|null,
     *     allowedModels?: list<string>|null,
     *     expiresAt?: \DateTimeImmutable|null,
     *     enabled?: bool,
     * } $attributes
     */
    protected function createApiKey(array $attributes = []): string
    {
        $generator = self::getContainer()->get(ApiKeyGenerator::class);
        $generated = $generator->generate();

        $apiKey = (new ApiKey())
            ->setName($attributes['name'] ?? 'Test key')
            ->setKeyMaterial($generated->getPrefix(), $generated->getHash())
            ->setMonthlyRequestLimit($attributes['monthlyRequestLimit'] ?? null)
            ->setMonthlyTokenLimit($attributes['monthlyTokenLimit'] ?? null)
            ->setAllowedModels($attributes['allowedModels'] ?? null)
            ->setExpiresAt($attributes['expiresAt'] ?? null)
            ->setEnabled($attributes['enabled'] ?? true);

        if (array_key_exists('monthlyBudgetUsd', $attributes) && null !== $attributes['monthlyBudgetUsd']) {
            $apiKey->setMonthlyBudgetUsd($attributes['monthlyBudgetUsd']);
        }

        /** @var ApiKeyRepository $repository */
        $repository = self::getContainer()->get(ApiKeyRepository::class);
        $repository->createOrUpdate($apiKey);

        return $generated->getSecret();
    }

    /**
     * The chat completions URL of a provider, as configured for the test environment.
     */
    protected function providerUrl(string $baseUrlVariable): string
    {
        $baseUrl = $_ENV[$baseUrlVariable] ?? $_SERVER[$baseUrlVariable] ?? '';

        $this->assertNotSame('', $baseUrl, \sprintf('The %s environment variable must be set.', $baseUrlVariable));

        return rtrim((string) $baseUrl, '/') . '/v1/chat/completions';
    }

    /**
     * @return array<string, string>
     */
    protected function gatewayHeaders(string $secret): array
    {
        return [
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * A provider answer shaped like an OpenAI-compatible chat completion.
     *
     * @return array<string, mixed>
     */
    protected static function chatCompletionPayload(
        string $content = 'Hello from the provider!',
        int $promptTokens = 1_000,
        int $completionTokens = 500,
        string $model = 'Mistral-Small-3.2-24B-Instruct-2506',
        ?string $finishReason = 'stop',
    ): array {
        return [
            'id' => 'provider-request-1',
            'object' => 'chat.completion',
            'created' => 1_700_000_000,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => $finishReason,
            ]],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }
}
