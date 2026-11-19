<?php

namespace App\Pixelforge;

use App\Support\Nano;

/**
 * Pixelforge's published price list (GET /v1/pricing) and the cost maths built on it.
 * resources/js/lib/pricing.ts mirrors maxUserPriceNano() for the live preview; this class is authoritative.
 */
final class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /** @param array<string, mixed> $list the /v1/pricing payload */
    public function __construct(public readonly array $list) {}

    /**
     * Exact cost of a finished generation, at the rates fixed on that generation.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    public static function costNano(array $usage, array $rates): int
    {
        $total = 0;
        foreach (self::TOKEN_TYPES as $type) {
            $total += (int) $usage[$type] * Nano::perTokenFromRate((string) $rates[$type]);
        }

        return $total;
    }

    /**
     * The most a generation can cost the user (cost × 1.25), assuming the most text and reasoning tokens possible.
     * Pass $rates to price with the rates Pixelforge fixed on a generation instead of today's list.
     *
     * @param  array<string, string>|null  $rates
     */
    public function maxUserPriceNano(string $prompt, string $size, string $quality, int $referenceImages, ?array $rates = null): int
    {
        $tokens = $this->list['tokens'];
        $estimatedText = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $varianceBasisPoints = (int) round($tokens['text_token_variance'] * 10_000);

        $maxUsage = [
            'input_text_tokens' => intdiv($estimatedText * (10_000 + $varianceBasisPoints) + 9_999, 10_000),
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
            'reasoning_tokens' => $tokens['reasoning'][$quality]['max'],
        ];

        return Nano::withMarkup(self::costNano($maxUsage, $rates ?? $this->list['rates']));
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_map(strval(...), array_keys($this->list['tokens']['output']['medium']));
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_map(strval(...), array_keys($this->list['tokens']['output']));
    }
}
