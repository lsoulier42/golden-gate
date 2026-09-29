<?php

namespace App\AI\Exception;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\InvalidArgumentException as SymfonyAiInvalidArgumentException;
use Symfony\AI\Platform\Exception\InvalidRequestException;
use Symfony\AI\Platform\Exception\ModelNotFoundException as SymfonyAiModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * A provider call failed.
 *
 * The public message is always safe to expose: it never carries the provider
 * credential. The raw provider message is kept aside for server-side logs only.
 */
final class ProviderCallException extends \RuntimeException implements GoldenGateExceptionInterface
{
    public function __construct(
        string $message,
        private readonly string $errorCategory,
        private readonly bool $timeout = false,
        private readonly ?string $providerDetail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Normalizes any failure raised while talking to a provider, including the ones
     * Symfony AI or Symfony HttpClient wrap several layers deep.
     */
    public static function fromThrowable(\Throwable $exception): self
    {
        $detail = self::deepestMessage($exception);

        if (null !== self::findTransportFailure($exception)) {
            $isTimeout = 1 === preg_match('/timed?\s*out|timeout/i', $detail);

            return new self(
                message: $isTimeout
                    ? 'The upstream provider did not answer in time.'
                    : 'The upstream provider is unreachable.',
                errorCategory: $isTimeout ? 'provider_timeout' : 'provider_unreachable',
                timeout: $isTimeout,
                providerDetail: $detail,
                previous: $exception,
            );
        }

        return new self(
            message: self::normalizeMessage($detail),
            errorCategory: self::categorize($exception),
            providerDetail: $detail,
            previous: $exception,
        );
    }

    /**
     * Machine-readable failure category, persisted with the usage record.
     */
    public function getErrorCategory(): string
    {
        return $this->errorCategory;
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    /**
     * Raw provider message, for server-side logs only.
     */
    public function getProviderDetail(): ?string
    {
        return $this->providerDetail;
    }

    private static function categorize(\Throwable $exception): string
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            $category = match (true) {
                $current instanceof AuthenticationException => 'provider_authentication_failed',
                $current instanceof RateLimitExceededException => 'provider_rate_limited',
                $current instanceof ExceedContextSizeException => 'provider_context_size_exceeded',
                $current instanceof ContentFilterException => 'provider_content_filtered',
                $current instanceof BadRequestException,
                $current instanceof InvalidRequestException,
                $current instanceof SymfonyAiInvalidArgumentException => 'provider_invalid_request',
                $current instanceof SymfonyAiModelNotFoundException => 'provider_model_not_found',
                $current instanceof ServerException => 'provider_server_error',
                default => null,
            };

            if (null !== $category) {
                return $category;
            }
        }

        return 'provider_error';
    }

    private static function findTransportFailure(\Throwable $exception): ?TransportExceptionInterface
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof TransportExceptionInterface) {
                return $current;
            }
        }

        return null;
    }

    /**
     * The innermost message carries the provider's own explanation; the outer ones
     * are usually generic bridge or transport wrappers.
     */
    private static function deepestMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ('' !== trim($current->getMessage())) {
                $message = $current->getMessage();
            }
        }

        return $message;
    }

    /**
     * Keeps the provider message short and strips anything that looks like a credential,
     * since it is surfaced to the calling application.
     */
    private static function normalizeMessage(string $providerMessage): string
    {
        $message = trim($providerMessage);

        if ('' === $message) {
            return 'The upstream provider rejected the request.';
        }

        $message = preg_replace('/\b(?:sk|api|key|token)[-_a-zA-Z0-9]{12,}\b/i', '[redacted]', $message) ?? $message;

        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '…';
        }

        return $message;
    }
}
