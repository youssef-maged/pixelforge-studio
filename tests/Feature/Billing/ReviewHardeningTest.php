<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => usePricingFixture());

it('caps a balance at $50 so free credits cannot be stockpiled', function () {
    $user = User::factory()->create(['balance_nano' => 45_000_000_000]);

    $this->actingAs($user)->post('/credits', ['pack' => 10, 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasErrors('pack');
    $this->actingAs($user)->post('/credits', ['pack' => 5, 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->balance_nano)->toBe(50_000_000_000);
});

it('throttles sign-ups per network', function () {
    RateLimiter::clear('register:127.0.0.1');
    foreach (range(1, 3) as $n) {
        $this->post('/register', ['name' => "U$n", 'email' => "u$n@example.com", 'password' => 'password', 'password_confirmation' => 'password']);
        auth()->logout();
    }

    $this->post('/register', ['name' => 'U4', 'email' => 'u4@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasErrors('email');
    expect(User::where('email', 'u4@example.com')->exists())->toBeFalse();
});

it('refuses to delete an account while a generation is in flight', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Processing]);

    $this->actingAs($generation->user)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');

    expect($generation->user->fresh()->name)->not->toBe('Deleted user');
});

it('keeps generations and the ledger when an account is deleted', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Failed, 'hold_nano' => 0, 'error_code' => 'generation_failed', 'completed_at' => now()]);

    $this->actingAs($generation->user)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();

    expect(Generation::find($generation->id))->not->toBeNull();
});

it('does not record an image copy that failed to save', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Succeeded,
        'usage' => ['output_tokens' => 1], 'provider_cost_nano' => 8, 'charge_nano' => 10, 'hold_nano' => 0,
        'image_url' => rtrim(config('services.pixelforge.url'), '/').'/files/x.png',
        'image_url_expires_at' => now()->addDay(),
    ]);
    Http::fake(['*/files/*' => Http::response('png-bytes')]);
    Storage::shouldReceive('disk->put')->andReturnFalse();

    expect(fn () => (new StoreGenerationImage($generation))->handle())->toThrow(RuntimeException::class);
    expect($generation->fresh()->image_path)->toBeNull();
});

it('links a webhook event to its generation only after applying it', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_link']);

    sendWebhook($this, providerObject($generation, 'processing'), 'generation.processing', 'evt_link')->assertNoContent();

    expect(WebhookEvent::find('evt_link')->generation_id)->toBe($generation->id);
});

it('never matches a webhook without any id to some random generation', function () {
    Generation::factory()->create(['provider_id' => null]);

    sendWebhook($this, ['object' => 'generation', 'status' => 'succeeded', 'metadata' => []], 'generation.succeeded', 'evt_noid')->assertNoContent();

    expect(WebhookEvent::find('evt_noid')->outcome)->toBe('no matching generation');
});
