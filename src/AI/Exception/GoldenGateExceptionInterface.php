<?php

namespace App\AI\Exception;

/**
 * Marker for every exception raised by the Golden Gate AI layer, so the HTTP layer
 * can distinguish gateway failures from unrelated application errors.
 */
interface GoldenGateExceptionInterface extends \Throwable
{
}
