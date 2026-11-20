<?php

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(RefreshDatabase::class)->in('Feature/Billing');

/** The real /v1/pricing payload, snapshotted from the docs. */
function pricingList(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/pricing.json'), true);
}

function usePricingFixture(): void
{
    Cache::forever('pixelforge.pricing', pricingList());
    Cache::forever('pixelforge.pricing.fetched_at', PHP_INT_MAX);
}

/** A Pixelforge generation object for one of our generations, as the API and webhooks send it. */
function providerObject(Generation $generation, string $status, array $overrides = []): array
{
    $base = [
        'id' => $generation->provider_id ?? 'gen_'.substr(md5($generation->id), 0, 26),
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_id' => $generation->id],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pricingList()['rates'],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? '2026-11-04T14:03:13Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-04T14:03:29Z' : null,
    ];

    if ($status === 'succeeded') {
        $base['image_url'] = 'https://pixelforge.promptedit.dev/images/x.png?signature=abc';
        $base['image_url_expires_at'] = now()->addDay()->toIso8601ZuluString();
        // The docs' worked usage: 12 + 765 + 6,450 + 3,184 tokens = $0.305510000 at the published rates.
        $base['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];
    }

    if ($status === 'failed') {
        $base['error'] = ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'];
    }

    return array_replace_recursive($base, $overrides);
}

/** POST a correctly signed webhook (or a tampered one) the way Pixelforge does. */
function sendWebhook(TestCase $test, array $object, string $type, ?string $eventId = null, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    $body = json_encode([
        'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601ZuluString(),
        'data' => ['object' => $object],
    ]);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? config('services.pixelforge.webhook_secret'));

    return $test->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ], content: $body);
}
