<?php

namespace App\Gateway\Error;

/**
 * A gateway request must be refused, with a stable error code.
 *
 * Thrown by the access-control, quota and provider layers and turned into a
 * GatewayErrorResponse by the gateway HTTP layer.
 */
final class GatewayException extends \RuntimeException
{
    public function __construct(
        private readonly GatewayErrorCode $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(GatewayErrorCode::InvalidRequest, $message);
    }

    public static function modelNotFound(string $modelAlias): self
    {
        return new self(
            GatewayErrorCode::ModelNotFound,
            \sprintf('The model "%s" does not exist.', $modelAlias),
        );
    }

    public static function modelNotAllowed(string $modelAlias): self
    {
        return new self(
            GatewayErrorCode::ModelNotAllowed,
            \sprintf('This API key is not allowed to use the model "%s".', $modelAlias),
        );
    }

    public static function providerFailed(string $message, bool $timeout = false): self
    {
        return new self(
            $timeout ? GatewayErrorCode::ProviderTimeout : GatewayErrorCode::ProviderError,
            $message,
        );
    }

    public function getErrorCode(): GatewayErrorCode
    {
        return $this->errorCode;
    }
}
