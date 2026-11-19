<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PixelforgeClient
{
    public function __construct(
        #[Config('services.pixelforge.url')] private string $url,
        #[Config('services.pixelforge.key')] private ?string $key,
    ) {}

    /** POST /v1/generations. Our generation id is the Idempotency-Key, so any retry returns the same generation. */
    public function createGeneration(Generation $generation): Response
    {
        return $this->http()
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ]);
    }

    public function generation(string $providerId): Response
    {
        return $this->http()->get("generations/{$providerId}");
    }

    public function usage(string $startDate, string $endDate): Response
    {
        return $this->http()->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * The price list. $fresh refetches it (used right before holding credits); otherwise a cached copy is fine.
     * If Pixelforge is unreachable we fall back to the last list we saw.
     */
    public function pricing(bool $fresh = false): Pricing
    {
        $cached = Cache::get('pixelforge.pricing');

        if ($fresh || $cached === null) {
            try {
                $cached = Http::baseUrl($this->url)->acceptJson()->timeout(5)->get('pricing')->throw()->json();
                Cache::forever('pixelforge.pricing', $cached);
            } catch (Throwable $e) {
                throw_if($cached === null, $e);
                report($e);
            }
        }

        return new Pricing($cached);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->url)
            ->withToken((string) $this->key)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }
}
