<?php

namespace App\Money;

use App\Money\Exception\InvalidAmountException;

/**
 * An exact USD amount, canonicalized as an integer number of micro-USD
 * (1 micro-USD = 0.000001 USD).
 *
 * Money never goes through floating point in this application: prices are integers
 * of micro-USD per million tokens, costs are integer arithmetic, and the database
 * stores the integer. Floats only appear when an amount is formatted for JSON.
 */
final class UsdAmount
{
    public const MICRO_USD_PER_USD = 1_000_000;

    private function __construct(private readonly int $microUsd)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromMicroUsd(int $microUsd): self
    {
        return new self($microUsd);
    }

    /**
     * Accepts the shapes an amount takes when it comes from configuration, the
     * database or an HTTP payload: int, float or numeric string.
     *
     * @throws InvalidAmountException
     */
    public static function fromUsd(int|float|string $usd): self
    {
        if (\is_int($usd)) {
            return new self($usd * self::MICRO_USD_PER_USD);
        }

        $normalized = \is_float($usd) ? self::floatToString($usd) : trim($usd);

        if (1 !== preg_match('/^(?<sign>-?)(?<units>\d+)(?:\.(?<decimals>\d+))?$/', $normalized, $matches)) {
            throw new InvalidAmountException(\sprintf('"%s" is not a valid USD amount.', $normalized));
        }

        $microUsd = (int) $matches['units'] * self::MICRO_USD_PER_USD;
        $decimals = $matches['decimals'] ?? '';

        if ('' !== $decimals) {
            // Keep six decimal places, rounding half up on the last one.
            $microUsd += (int) round(((float) ('0.' . $decimals)) * self::MICRO_USD_PER_USD);
        }

        return new self('-' === $matches['sign'] ? -$microUsd : $microUsd);
    }

    public function toMicroUsd(): int
    {
        return $this->microUsd;
    }

    public function toUsd(): float
    {
        return $this->microUsd / self::MICRO_USD_PER_USD;
    }

    /**
     * Fixed-scale decimal representation, suitable for reporting.
     */
    public function toDecimalString(int $scale = 8): string
    {
        if ($scale < 0) {
            throw new InvalidAmountException('The scale of a decimal amount cannot be negative.');
        }

        $sign = $this->microUsd < 0 ? '-' : '';
        $absolute = abs($this->microUsd);
        $digits = str_pad((string) intdiv($absolute, self::MICRO_USD_PER_USD), 1, '0', \STR_PAD_LEFT);

        if (0 === $scale) {
            return $sign . $digits;
        }

        $fraction = str_pad((string) ($absolute % self::MICRO_USD_PER_USD), 6, '0', \STR_PAD_LEFT);
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return $sign . $digits . '.' . $fraction;
    }

    public function isNegative(): bool
    {
        return $this->microUsd < 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->microUsd >= $other->microUsd;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->microUsd > $other->microUsd;
    }

    public function plus(self $other): self
    {
        return new self($this->microUsd + $other->microUsd);
    }

    private static function floatToString(float $usd): string
    {
        if (!is_finite($usd)) {
            throw new InvalidAmountException('A USD amount must be a finite number.');
        }

        // %.8F keeps the value out of scientific notation and uses a dot separator.
        return rtrim(rtrim(sprintf('%.8F', $usd), '0'), '.');
    }
}
