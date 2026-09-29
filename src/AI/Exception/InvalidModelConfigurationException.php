<?php

namespace App\AI\Exception;

use InvalidArgumentException;

/**
 * The `golden_gate.models` configuration is not usable: missing alias, provider
 * or upstream identifier, negative price, ...
 */
final class InvalidModelConfigurationException extends InvalidArgumentException implements GoldenGateExceptionInterface
{
}
