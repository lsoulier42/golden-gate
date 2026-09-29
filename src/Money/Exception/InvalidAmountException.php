<?php

namespace App\Money\Exception;

/**
 * A monetary amount could not be interpreted exactly (malformed string,
 * non-finite float, impossible scale).
 */
final class InvalidAmountException extends \InvalidArgumentException
{
}
