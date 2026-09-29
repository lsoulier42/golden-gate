<?php

namespace App\AI\Exception;

/**
 * The caller sent a chat request the gateway cannot honour: unsupported role,
 * empty message list, out-of-range sampling parameter, ...
 *
 * Mapped to the `invalid_request_error` error code by the gateway HTTP layer.
 */
final class InvalidChatRequestException extends \InvalidArgumentException implements GoldenGateExceptionInterface
{
}
