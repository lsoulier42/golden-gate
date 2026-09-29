<?php

namespace App\Tests\Http;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for every outbound HTTP call in the test environment.
 *
 * Registered as `framework.http_client.mock_response_factory`, it makes the whole
 * suite hermetic: no test can reach a real provider, and a test that forgets to
 * queue an answer fails loudly instead of silently spending API credits.
 *
 * The queue and the request log are static: a functional test client reboots the
 * kernel between requests, which would otherwise hand the transport a brand new
 * (and empty) factory on every call.
 */
final class ProviderMockResponseFactory
{
    /**
     * @var list<array{payload: array<string, mixed>|string, status: int, transportError: string|null}>
     */
    private static array $queue = [];

    /**
     * @var list<array{method: string, url: string, body: array<string, mixed>, headers: array<int, string>}>
     */
    private static array $requests = [];

    /**
     * Queues the answer of the next provider call.
     *
     * @param array<string, mixed> $payload
     */
    public function queueJson(array $payload, int $status = 200): void
    {
        self::$queue[] = [
            'payload' => $payload,
            'status' => $status,
            'transportError' => null,
        ];
    }

    /**
     * Queues a transport-level failure, such as a timeout.
     */
    public function queueTransportFailure(string $message = 'Idle timeout reached for the provider.'): void
    {
        self::$queue[] = [
            'payload' => '',
            'status' => 0,
            'transportError' => $message,
        ];
    }

    public function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    /**
     * @return list<array{method: string, url: string, body: array<string, mixed>, headers: array<int, string>}>
     */
    public function requests(): array
    {
        return self::$requests;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $decoded = json_decode($options['body'] ?? '{}', true);
        self::$requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => \is_array($decoded) ? $decoded : [],
            'headers' => $options['headers'] ?? [],
        ];

        $next = array_shift(self::$queue);

        if (null === $next) {
            $message = \sprintf('No provider response was queued for %s %s.', $method, $url);

            return new MockResponse(
                json_encode(['error' => ['message' => $message]], \JSON_THROW_ON_ERROR),
                ['http_code' => 500, 'response_headers' => ['content-type' => 'application/json']],
            );
        }

        if (null !== $next['transportError']) {
            return new MockResponse('', ['error' => $next['transportError']]);
        }

        return new MockResponse(
            \is_array($next['payload']) ? json_encode($next['payload'], \JSON_THROW_ON_ERROR) : $next['payload'],
            ['http_code' => $next['status'], 'response_headers' => ['content-type' => 'application/json']],
        );
    }
}
