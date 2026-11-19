<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money as integer nanodollars (1e-9 USD). Pixelforge prices are exact to 9 decimals,
 * so this is lossless, and floats never touch money.
 */
final class Nano
{
    /** "0.046125000" → 46125000 */
    public static function fromDecimal(string $dollars): int
    {
        return self::scale($dollars, 9);
    }

    /** A rate in dollars per 1M tokens ("12.50") → nanodollars per token (12500). */
    public static function perTokenFromRate(string $ratePerMillion): int
    {
        return self::scale($ratePerMillion, 3);
    }

    /** 46125000 → "0.046125000" */
    public static function toDecimal(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $nano = abs($nano);

        return sprintf('%s%d.%09d', $sign, intdiv($nano, 1_000_000_000), $nano % 1_000_000_000);
    }

    /** Provider cost × 1.25, rounded up to the next nanodollar if it is ever not exact. */
    public static function withMarkup(int $providerNano): int
    {
        return intdiv($providerNano * 5 + 3, 4);
    }

    /** Parse a non-negative decimal string into an integer of 10^-$places units, refusing to drop precision. */
    private static function scale(string $decimal, int $places): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$decimal}]");
        }

        $fraction = $m[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > $places) {
            throw new InvalidArgumentException("[{$decimal}] has more precision than 1e-{$places}");
        }

        return (int) $m[1] * 10 ** $places + (int) str_pad(substr($fraction, 0, $places), $places, '0');
    }
}
