<?php

use App\Actions\RecordGenerationState;
use App\Actions\StartGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    usePricingFixture();
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    Queue::fake([StoreGenerationImage::class]);
    $this->user = User::factory()->create(['balance_nano' => 1_000_000_000]);
    $this->generation = Generation::factory()->for($this->user)->create([
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'rates' => pricingList()['rates'],
    ]);
});

it('rejects unsigned, tampered, wrongly keyed and stale requests', function () {
    $object = providerObject($this->generation, 'succeeded');

    $this->postJson('/webhooks/pixelforge', ['type' => 'generation.succeeded'])->assertStatus(400);
    sendWebhook($this, $object, 'generation.succeeded', secret: 'whsec_wrong')->assertStatus(400);
    sendWebhook($this, $object, 'generation.succeeded', timestamp: time() - 301)->assertStatus(400);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('charges exactly cost × 1.25 on success, once', function () {
    sendWebhook($this, providerObject($this->generation, 'succeeded'), 'generation.succeeded')->assertNoContent();

    // usage 12/765/6450/3184 at 5/10/40/12.50 per 1M = $0.305510000 → user $0.3818875
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nano->toBe(305_510_000)
        ->charge_nano->toBe(381_887_500)
        ->hold_nano->toBe(0)
        ->completed_at->toIso8601ZuluString()->toBe('2026-11-04T14:03:29Z')
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 381_887_500)
        ->and(CreditTransaction::sole()->amount_nano)->toBe(-381_887_500);
    Queue::assertPushed(StoreGenerationImage::class);
});

it('records an event delivered twice only once', function () {
    $object = providerObject($this->generation, 'succeeded');

    sendWebhook($this, $object, 'generation.succeeded', eventId: 'evt_same')->assertNoContent();
    sendWebhook($this, $object, 'generation.succeeded', eventId: 'evt_same')->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(CreditTransaction::count())->toBe(1);
});

it('never charges twice, even for two different success events', function () {
    sendWebhook($this, providerObject($this->generation, 'succeeded'), 'generation.succeeded');
    sendWebhook($this, providerObject($this->generation, 'succeeded'), 'generation.succeeded');

    expect(CreditTransaction::count())->toBe(1);
});

it('ignores a late processing event that arrives after success', function () {
    sendWebhook($this, providerObject($this->generation, 'succeeded'), 'generation.succeeded');
    sendWebhook($this, providerObject($this->generation, 'processing'), 'generation.processing')->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('never moves a failed generation back', function () {
    sendWebhook($this, providerObject($this->generation, 'failed'), 'generation.failed');
    sendWebhook($this, providerObject($this->generation, 'processing'), 'generation.processing');

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->hold_nano->toBe(0)
        ->and(CreditTransaction::count())->toBe(0);
});

it('makes failures free and releases the hold', function () {
    sendWebhook($this, providerObject($this->generation, 'failed'), 'generation.failed')->assertNoContent();

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('internal_error')
        ->charge_nano->toBeNull()
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000)
        ->and($this->user->fresh()->heldNano())->toBe(0);
});

it('handles a webhook that arrives before our POST returned', function () {
    $pending = Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Pending]);

    sendWebhook($this, providerObject($pending, 'succeeded', ['id' => 'gen_early']), 'generation.succeeded')->assertNoContent();

    // Then the slow POST response finally lands, saying "queued".
    app(RecordGenerationState::class)->apply($pending, providerObject($pending, 'queued', ['id' => 'gen_early']));

    expect($pending->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_early')
        ->and(CreditTransaction::count())->toBe(1);
});

it('adopts a generation we gave up on if Pixelforge did create it, so it is never billed uncharged', function () {
    $givenUp = Generation::factory()->for($this->user)->create([
        'status' => GenerationStatus::Failed, 'error_code' => 'provider_busy', 'hold_nano' => 0, 'completed_at' => now(),
    ]);

    sendWebhook($this, providerObject($givenUp, 'succeeded'), 'generation.succeeded');

    expect($givenUp->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->error_code->toBeNull()
        ->and(CreditTransaction::count())->toBe(1);
});

it('charges the true cost if Pixelforge fixed higher rates, then blocks until a top-up', function () {
    $doubled = ['input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00'];
    $this->user->forceFill(['balance_nano' => 500_000_000])->save();
    $pending = Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Pending]);

    sendWebhook($this, providerObject($pending, 'processing', ['rates' => $doubled]), 'generation.processing');
    expect($pending->fresh()->hold_nano)->toBeGreaterThan($pending->estimate_nano);

    sendWebhook($this, providerObject($pending, 'succeeded', ['rates' => $doubled]), 'generation.succeeded');

    expect($pending->fresh()->charge_nano)->toBe(763_775_000)
        ->and($this->user->fresh()->balance_nano)->toBe(500_000_000 - 763_775_000);

    expect(fn () => app(StartGeneration::class)($this->user, 'x', '1024x1024', 'low', [], (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

it('answers pings and ignores events for generations it does not know', function () {
    sendWebhook($this, [], 'ping')->assertNoContent();
    sendWebhook($this, ['id' => 'gen_unknown', 'status' => 'succeeded', 'metadata' => []], 'generation.succeeded')->assertNoContent();

    expect(CreditTransaction::count())->toBe(0);
});

it('logs what each event did and counts duplicate deliveries', function () {
    $object = providerObject($this->generation, 'succeeded');

    sendWebhook($this, $object, 'generation.succeeded', eventId: 'evt_twice');
    sendWebhook($this, $object, 'generation.succeeded', eventId: 'evt_twice');
    sendWebhook($this, providerObject($this->generation, 'processing'), 'generation.processing', eventId: 'evt_late');

    expect(WebhookEvent::find('evt_twice'))->deliveries->toBe(2)->outcome->toBe('moved queued → succeeded')
        ->and(WebhookEvent::find('evt_late')->outcome)->toBe('no change (already succeeded)');
});
