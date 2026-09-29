<?php

namespace App\AI\Exception;

/**
 * The requested public model alias is unknown or disabled.
 *
 * Mapped to the `model_not_found` error code by the gateway HTTP layer.
 */
final class ModelNotFoundException extends \RuntimeException implements GoldenGateExceptionInterface
{
}
