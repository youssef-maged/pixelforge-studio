import type { PricingList } from '@/types/studio';

/**
 * Live preview of the "up to" price, mirroring App\Pixelforge\Pricing::maxUserPriceNano().
 * The server recomputes it on submit with a freshly fetched price list; that number is the one held and recorded.
 */
export function maxUserPriceNano(
    list: PricingList,
    prompt: string,
    size: string,
    quality: string,
    referenceImages: number,
): bigint {
    const tokens = list.tokens;
    const characters = Array.from(prompt.trim()).length; // Unicode code points, like PHP's mb_strlen
    const estimatedText = Math.ceil(
        characters / tokens.characters_per_text_token,
    );
    const varianceBasisPoints = BigInt(
        Math.round(tokens.text_token_variance * 10_000),
    );

    const maxUsage: Record<string, bigint> = {
        input_text_tokens:
            (BigInt(estimatedText) * (10_000n + varianceBasisPoints) + 9_999n) /
            10_000n,
        input_image_tokens: BigInt(
            referenceImages * tokens.per_reference_image,
        ),
        output_tokens: BigInt(tokens.output[quality]?.[size] ?? 0),
        reasoning_tokens: BigInt(tokens.reasoning[quality]?.max ?? 0),
    };

    const cost = Object.entries(maxUsage).reduce(
        (sum, [type, count]) => sum + count * nanoPerToken(list.rates[type]),
        0n,
    );

    return (cost * 5n + 3n) / 4n; // × 1.25, rounded up to the nanodollar
}

/** "12.50" dollars per 1M tokens → 12500n nanodollars per token. */
function nanoPerToken(ratePerMillion: string): bigint {
    const [whole, fraction = ''] = ratePerMillion.split('.');

    return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0').slice(0, 3));
}
