<?php

declare(strict_types=1);

namespace Xyr\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Xyr\Payments\Money;

final class MoneyTest extends TestCase
{
    public function testConvertsDecimalStringsToCentsExactly(): void
    {
        $this->assertSame(38500, Money::toCents('385.00'));
        $this->assertSame(1999, Money::toCents('19.99')); // (int)(19.99 * 100) would give 1998
        $this->assertSame(1950, Money::toCents('19.5'));
        $this->assertSame(7, Money::toCents('0.07'));
    }

    public function testRoundTripsBackToDecimal(): void
    {
        $this->assertSame('19.99', Money::toDecimal(1999));
        $this->assertSame('0.07', Money::toDecimal(7));
        $this->assertSame('385.00', Money::toDecimal(38500));
    }

    public function testRejectsAmbiguousInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toCents('19.999');
    }
}
