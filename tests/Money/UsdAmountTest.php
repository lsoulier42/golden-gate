<?php

namespace App\Tests\Money;

use App\Money\Exception\InvalidAmountException;
use App\Money\UsdAmount;
use PHPUnit\Framework\TestCase;

/**
 * Money is canonicalized as integer micro-USD, so these tests pin the exact
 * conversions between the shapes an amount takes: config floats, HTTP payloads,
 * database integers and JSON output.
 */
final class UsdAmountTest extends TestCase
{
    public function testAnAmountIsStoredAsMicroUsd(): void
    {
        $this->assertSame(5_000_000, UsdAmount::fromUsd(5)->toMicroUsd());
        $this->assertSame(5_500_000, UsdAmount::fromUsd(5.5)->toMicroUsd());
        $this->assertSame(5_500_000, UsdAmount::fromUsd('5.5')->toMicroUsd());
        $this->assertSame(1, UsdAmount::fromUsd('0.000001')->toMicroUsd());
        $this->assertSame(0, UsdAmount::fromUsd(0)->toMicroUsd());
    }

    public function testParsingIsExactForValuesThatFloatsCannotRepresent(): void
    {
        // 0.1 + 0.2 is famously not 0.3 in binary floating point.
        $this->assertSame(300_000, UsdAmount::fromUsd('0.1')->plus(UsdAmount::fromUsd('0.2'))->toMicroUsd());
        $this->assertSame(10, UsdAmount::fromUsd('0.00001')->toMicroUsd());
        $this->assertSame(123_456, UsdAmount::fromUsd('0.123456')->toMicroUsd());
    }

    public function testSubMicroAmountsRoundToTheNearestMicroUsd(): void
    {
        $this->assertSame(1, UsdAmount::fromUsd('0.0000005')->toMicroUsd());
        $this->assertSame(2, UsdAmount::fromUsd('0.0000015')->toMicroUsd());
        $this->assertSame(0, UsdAmount::fromUsd('0.0000004')->toMicroUsd());
    }

    public function testNegativeAmountsAreSupported(): void
    {
        $this->assertSame(-2_500_000, UsdAmount::fromUsd('-2.5')->toMicroUsd());
        $this->assertSame('-2.50000000', UsdAmount::fromUsd('-2.5')->toDecimalString());
        $this->assertTrue(UsdAmount::fromUsd('-1')->isNegative());
    }

    public function testAmountsAreFormattedWithAFixedScale(): void
    {
        $this->assertSame('0.00025000', UsdAmount::fromMicroUsd(250)->toDecimalString());
        $this->assertSame('5.00000000', UsdAmount::fromUsd(5)->toDecimalString());
        $this->assertSame('5.50', UsdAmount::fromUsd(5.5)->toDecimalString(2));
        $this->assertSame('5', UsdAmount::fromUsd(5)->toDecimalString(0));
        $this->assertSame('0.00000000', UsdAmount::zero()->toDecimalString());
    }

    public function testAmountsAreComparedExactly(): void
    {
        $budget = UsdAmount::fromUsd('0.00025');

        $this->assertTrue($budget->isGreaterThanOrEqualTo(UsdAmount::fromMicroUsd(250)));
        $this->assertTrue($budget->isGreaterThan(UsdAmount::fromMicroUsd(249)));
        $this->assertFalse($budget->isGreaterThan(UsdAmount::fromMicroUsd(250)));
        $this->assertFalse(UsdAmount::zero()->isGreaterThan(UsdAmount::zero()));
        $this->assertTrue(UsdAmount::fromUsd(1)->isGreaterThanOrEqualTo(UsdAmount::zero()));
    }

    public function testFloatsBeyondTheSupportedPrecisionStayFiniteAndExactEnough(): void
    {
        // A value written in scientific notation must not break the parser.
        $this->assertSame(1, UsdAmount::fromUsd(1.0E-6)->toMicroUsd());
        $this->assertSame(100, UsdAmount::fromUsd(1.0E-4)->toMicroUsd());
    }

    public function testAMalformedAmountIsRejected(): void
    {
        $this->expectException(InvalidAmountException::class);

        UsdAmount::fromUsd('five dollars');
    }

    public function testANonFiniteAmountIsRejected(): void
    {
        $this->expectException(InvalidAmountException::class);

        UsdAmount::fromUsd(\INF);
    }

    public function testANegativeScaleIsRejected(): void
    {
        $this->expectException(InvalidAmountException::class);

        UsdAmount::fromUsd(1)->toDecimalString(-1);
    }
}
