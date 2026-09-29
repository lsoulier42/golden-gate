<?php

namespace App\Tests\AI\Gateway;

use App\AI\Dto\ChatCompletionRequest;
use App\AI\Dto\ChatMessage;
use App\AI\Gateway\ModelGatewayInterface;
use App\AI\Gateway\SymfonyAiModelGateway;
use App\AI\Model\ModelRegistry;
use App\AI\Provider\PlatformFactory;
use App\AI\Provider\ProviderDefinition;
use App\AI\Provider\ProviderRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Proves the acceptance criterion of the provider-abstraction phase: the very same
 * normalized request goes through two different provider configurations (an
 * OpenAI-compatible endpoint and the native Mistral bridge) and comes back as the
 * same normalized result shape.
 */
final class SymfonyAiModelGatewayTest extends TestCase
{
    /**
     * @var list<array{method: string, url: string, body: array<string, mixed>, authorization: list<string>}>
     */
    private array $recordedRequests = [];

    private string $upstreamCompletionId = 'chatcmpl-1';

    private string $upstreamModelName = 'test-model';

    private function gateway(MockHttpClient $httpClient): ModelGatewayInterface
    {
        $providers = new ProviderRegistry([
            new ProviderDefinition('ovhcloud', 'generic', 'https://ovh.example.com', 'ovh-secret-key', 30.0),
            new ProviderDefinition('mistral', 'mistral', 'https://mistral.example.com', 'mistral-secret-key', 30.0),
        ]);

        $models = new ModelRegistry([
            'mistral-small' => [
                'provider' => 'ovhcloud',
                'upstream_model' => 'Mistral-Small-3.2-24B-Instruct-2506',
                'input_price_per_million_usd' => 0.10,
                'output_price_per_million_usd' => 0.30,
            ],
            'mistral-medium' => [
                'provider' => 'mistral',
                'upstream_model' => 'mistral-medium-latest',
                'input_price_per_million_usd' => 0.40,
                'output_price_per_million_usd' => 2.00,
            ],
        ]);

        return new SymfonyAiModelGateway($models, $providers, new PlatformFactory($httpClient));
    }

    /**
     * A mock client that records what was sent and answers with a provider-shaped payload.
     */
    private function recordingHttpClient(string $completionId, string $reportedModel): MockHttpClient
    {
        $this->upstreamCompletionId = $completionId;
        $this->upstreamModelName = $reportedModel;

        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'] ?? '{}', true);
            $this->recordedRequests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_array($body) ? $body : [],
                'authorization' => $options['headers'] ?? [],
            ];

            return new MockResponse(json_encode([
                'id' => $this->upstreamCompletionId,
                'object' => 'chat.completion',
                'created' => 1_700_000_000,
                'model' => $this->upstreamModelName,
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Bonjour !'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1_200,
                    'completion_tokens' => 800,
                    'total_tokens' => 2_000,
                ],
            ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
    }

    private function request(string $model): ChatCompletionRequest
    {
        return new ChatCompletionRequest(
            model: $model,
            messages: [
                ChatMessage::system('You are a helpful assistant.'),
                ChatMessage::user('Bonjour'),
            ],
            temperature: 0.7,
            maxTokens: 500,
        );
    }

    public function testTheSameRequestIsServedThroughAnOpenAiCompatibleProvider(): void
    {
        $httpClient = $this->recordingHttpClient('ovh-request-1', 'Mistral-Small-3.2-24B-Instruct-2506');

        $result = $this->gateway($httpClient)->complete($this->request('mistral-small'));

        $this->assertSame('Bonjour !', $result->getContent());
        $this->assertSame('mistral-small', $result->getModelAlias());
        $this->assertSame('stop', $result->getFinishReason());
        $this->assertSame('ovh-request-1', $result->getProviderRequestId());
        $this->assertSame(1_200, $result->getUsage()->getPromptTokens());
        $this->assertSame(800, $result->getUsage()->getCompletionTokens());
        $this->assertSame(2_000, $result->getUsage()->getTotalTokens());

        // 1200 input at 0.10 USD/M + 800 output at 0.30 USD/M = 360 micro-USD.
        $this->assertSame(360, $result->getEstimatedCostMicroUsd());

        $this->assertCount(1, $this->recordedRequests);
        $this->assertSame('POST', $this->recordedRequests[0]['method']);
        $this->assertSame('https://ovh.example.com/v1/chat/completions', $this->recordedRequests[0]['url']);
    }

    public function testTheSameRequestIsServedThroughTheNativeMistralProvider(): void
    {
        $httpClient = $this->recordingHttpClient('mistral-request-1', 'mistral-medium-2508');

        $result = $this->gateway($httpClient)->complete($this->request('mistral-medium'));

        $this->assertSame('Bonjour !', $result->getContent());
        $this->assertSame('mistral-medium', $result->getModelAlias());
        $this->assertSame('mistral', $result->getModel()->getProvider());
        $this->assertSame('mistral-request-1', $result->getProviderRequestId());
        $this->assertSame('mistral-medium-2508', $result->getProviderModel());
        $this->assertSame(1_200, $result->getUsage()->getPromptTokens());
        $this->assertSame(800, $result->getUsage()->getCompletionTokens());

        // 1200 input at 0.40 USD/M + 800 output at 2.00 USD/M = 2080 micro-USD.
        $this->assertSame(2_080, $result->getEstimatedCostMicroUsd());

        $this->assertCount(1, $this->recordedRequests);
        $this->assertSame('https://mistral.example.com/v1/chat/completions', $this->recordedRequests[0]['url']);
    }

    public function testBothProvidersReceiveTheSameNormalizedConversation(): void
    {
        $request = $this->request('mistral-small');

        $this->gateway($this->recordingHttpClient('a', 'model-a'))->complete($request);
        $this->gateway($this->recordingHttpClient('b', 'model-b'))->complete(
            new ChatCompletionRequest(
                model: 'mistral-medium',
                messages: $request->getMessages(),
                temperature: $request->getTemperature(),
                maxTokens: $request->getMaxTokens(),
            ),
        );

        $this->assertCount(2, $this->recordedRequests);

        foreach ($this->recordedRequests as $recorded) {
            $this->assertSame([
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'Bonjour'],
            ], $recorded['body']['messages']);
            $this->assertSame(0.7, $recorded['body']['temperature']);
            $this->assertSame(500, $recorded['body']['max_tokens']);
        }

        // Only the upstream model identifier differs: everything else is gateway policy.
        $this->assertSame('Mistral-Small-3.2-24B-Instruct-2506', $this->recordedRequests[0]['body']['model']);
        $this->assertSame('mistral-medium-latest', $this->recordedRequests[1]['body']['model']);
    }

    public function testEachProviderIsCalledWithItsOwnCredential(): void
    {
        $this->gateway($this->recordingHttpClient('a', 'model-a'))->complete($this->request('mistral-small'));
        $this->gateway($this->recordingHttpClient('b', 'model-b'))->complete($this->request('mistral-medium'));

        $this->assertSame('Bearer ovh-secret-key', $this->authorizationOf(0));
        $this->assertSame('Bearer mistral-secret-key', $this->authorizationOf(1));
    }

    private function authorizationOf(int $index): ?string
    {
        foreach ($this->recordedRequests[$index]['authorization'] as $header) {
            if (str_starts_with(strtolower((string) $header), 'authorization:')) {
                return trim(substr((string) $header, strlen('authorization:')));
            }
        }

        return null;
    }
}
