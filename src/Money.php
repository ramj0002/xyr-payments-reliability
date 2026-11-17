<?php

declare(strict_types=1);

namespace Xyr\Payments;

/**
 * Money is stored as integer cents everywhere in this layer.
 *
 * XYR keeps prices as DECIMAL(10,2). Converting through floats can turn
 * 19.99 into 1998 cents, so conversion is done on the string instead.
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        $s = trim((string) $amount);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw new \InvalidArgumentException("Not a money amount: '$s'");
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
