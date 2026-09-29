<?php

namespace App\AI\Exception;

/**
 * A model points at a provider that is not declared in the configuration.
 * This is an operator error, not a client error.
 */
final class ProviderNotFoundException extends \RuntimeException implements GoldenGateExceptionInterface
{
}
