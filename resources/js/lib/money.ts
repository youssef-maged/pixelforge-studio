/**
 * Money arrives from the server as exact decimal strings ("0.086312500") and is only ever
 * handled as BigInt nanodollars here. Floats never touch it.
 */
const NANO = 1_000_000_000n;

export function toNano(decimal: string): bigint {
    const negative = decimal.startsWith('-');
    const [whole, fraction = ''] = decimal.replace('-', '').split('.');
    const nano =
        BigInt(whole) * NANO + BigInt(fraction.padEnd(9, '0').slice(0, 9));

    return negative ? -nano : nano;
}

/**
 * "$0.0864". `up` rounds an upper bound upwards so a shown "up to" is never an understatement;
 * everything else rounds to nearest. The exact 9-decimal value belongs in a title attribute.
 */
export function usd(
    value: string | bigint,
    {
        places = 4,
        round = 'nearest',
    }: { places?: number; round?: 'up' | 'nearest' } = {},
): string {
    const nano = typeof value === 'bigint' ? value : toNano(value);
    const negative = nano < 0n;
    const abs = negative ? -nano : nano;
    const unit = 10n ** BigInt(9 - places);
    const units =
        round === 'up' ? (abs + unit - 1n) / unit : (abs + unit / 2n) / unit;
    const whole = units / 10n ** BigInt(places);
    const fraction = (units % 10n ** BigInt(places))
        .toString()
        .padStart(places, '0');

    return `${negative ? '−' : ''}$${whole.toLocaleString('en-US')}.${fraction}`;
}

/** "0.086312500" → "$0.086312500 exactly" for tooltips. */
export function exact(value: string): string {
    return `$${value.replace('-', '−')} exactly`;
}
