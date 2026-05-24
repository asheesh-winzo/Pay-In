<?php

declare(strict_types=1);

namespace App\Util;

final class Money
{
    /**
     * Convert a decimal string to integer minor units (cents).
     * "10.50" → 1050,  "100" → 10000,  "5.5" → 550
     */
    public static function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return (int) ($whole . $fraction);
    }

    /**
     * Convert integer minor units back to a decimal string.
     * 1050 → "10.50"
     */
    public static function toDecimal(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
