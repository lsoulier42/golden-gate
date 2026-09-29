<?php

namespace App\Gateway\Error;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The JSON error contract of `/v1/*`, inspired by OpenAI but owned by Golden Gate:
 *
 *     {
 *       "error": {
 *         "message": "Monthly budget exhausted.",
 *         "type": "quota_error",
 *         "code": "monthly_budget_exceeded"
 *       }
 *     }
 *
 * The message is always safe to expose to the calling application: no provider
 * credential, no clear-text Golden Gate key, no raw provider payload.
 */
final class GatewayErrorResponse extends JsonResponse
{
    public function __construct(GatewayErrorCode $code, string $message)
    {
        parent::__construct(
            [
                'error' => [
                    'message' => $message,
                    'type' => $code->type(),
                    'code' => $code->value,
                ],
            ],
            $code->httpStatus(),
        );

        $this->headers->set('Cache-Control', 'no-store');
    }

    public static function forException(GatewayException $exception): self
    {
        return new self($exception->getErrorCode(), $exception->getMessage());
    }
}
