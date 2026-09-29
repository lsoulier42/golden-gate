<?php

namespace App\Security\Exception;

use App\Gateway\Error\GatewayErrorCode;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Authentication failure of a `/v1/*` request.
 *
 * Extends the Security component's exception so the firewall hands it to the
 * authenticator's failure handler, and carries the gateway error code the caller
 * must branch on.
 */
final class GatewayAuthenticationException extends AuthenticationException
{
    public function __construct(
        private readonly GatewayErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidApiKey(string $message = 'Invalid Golden Gate API key.'): self
    {
        return new self(GatewayErrorCode::InvalidApiKey, $message);
    }

    public static function apiKeyDisabled(): self
    {
        return new self(GatewayErrorCode::ApiKeyDisabled, 'This API key is disabled.');
    }

    public static function apiKeyExpired(): self
    {
        return new self(GatewayErrorCode::ApiKeyExpired, 'This API key has expired.');
    }

    public function getErrorCode(): GatewayErrorCode
    {
        return $this->errorCode;
    }

    /**
     * The message is already safe to expose; it also avoids leaking the default
     * "Invalid credentials." wording of the Security component.
     */
    public function getMessageKey(): string
    {
        return $this->getMessage();
    }
}
