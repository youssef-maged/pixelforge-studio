<?php

use App\Actions\RecordGenerationState;
use App\Enums\GenerationStatus;
use App\Jobs\ReconcileGenerations;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    usePricingFixture();
    Http::fake(['*/v1/pricing' => Http::response(pricingList())]);
});

function submitForm(array $overrides = []): array
{
    return [
        'prompt' => 'A lighthouse on a cliff at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'client_request_id' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(RecordGenerationState::class));

    return $job;
}

it('holds the "up to" price and queues the submission', function () {
    Queue::fake();
    $user = User::factory()->create(['balance_nano' => 10_000_000_000]);

    $this->actingAs($user)->post('/generations', submitForm())->assertRedirect('/studio');

    $generation = $user->generations()->sole();
    // medium 1024x1024, 31 chars → text ≤ ceil(8 × 1.15) = 10, output 1,100, reasoning ≤ 2,000 → $0.06905 × 1.25
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->estimate_nano)->toBe(86_312_500)
        ->and($generation->hold_nano)->toBe(86_312_500)
        ->and($user->fresh()->heldNano())->toBe(86_312_500);
    Queue::assertPushed(SubmitGeneration::class);
});

it('creates one generation when the same form is submitted twice', function () {
    Queue::fake();
    $user = User::factory()->create(['balance_nano' => 10_000_000_000]);
    $form = submitForm();

    $this->actingAs($user)->post('/generations', $form);
    $this->actingAs($user)->post('/generations', $form);

    expect($user->generations()->count())->toBe(1);
});

it('blocks a generation the user cannot afford, before calling Pixelforge', function () {
    Queue::fake();
    $user = User::factory()->create(['balance_nano' => 50_000_000]);

    $this->actingAs($user)->post('/generations', submitForm())->assertSessionHasErrors('credits');

    expect($user->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('counts money held by in-flight generations as unavailable', function () {
    Queue::fake();
    $user = User::factory()->create(['balance_nano' => 100_000_000]);

    $this->actingAs($user)->post('/generations', submitForm())->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/generations', submitForm())->assertSessionHasErrors('credits');
});

it('caps each user at 3 generations in flight', function () {
    Queue::fake();
    $user = User::factory()->create(['balance_nano' => 10_000_000_000]);
    Generation::factory()->count(3)->for($user)->create(['status' => GenerationStatus::Processing, 'hold_nano' => 0]);

    $this->actingAs($user)->post('/generations', submitForm())->assertSessionHasErrors('prompt');
});

it('rejects duplicate reference images, which Pixelforge would bill twice', function () {
    $user = User::factory()->create(['balance_nano' => 10_000_000_000]);

    $this->actingAs($user)
        ->post('/generations', submitForm(['reference_images' => ['https://x.test/a.png', 'https://x.test/a.png']]))
        ->assertSessionHasErrors('reference_images.1');
});

it('sends our generation id as the idempotency key and metadata', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(providerObject($generation, 'queued'), 202)]);

    runSubmit($generation);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
        && $request->header('Idempotency-Key')[0] === $generation->id
        && $request['metadata'] === ['generation_id' => $generation->id]);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->provider_id)->not->toBeNull();
});

it('waits politely when Pixelforge is busy, then keeps the same key', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code]], $status, ['Retry-After' => '5'])]);

    runSubmit($generation)->assertReleased();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'concurrency' => [429, 'concurrency_limit_exceeded'],
    'rate limit' => [429, 'rate_limit_exceeded'],
    'overloaded' => [503, 'overloaded'],
]);

it('retries a timeout (unknown outcome) instead of failing it', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => fn () => throw new ConnectionException('timed out')]);

    runSubmit($generation)->assertReleased();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('gives up for free after 15 minutes of Pixelforge being busy', function () {
    $generation = Generation::factory()->create(['created_at' => now()->subMinutes(16)]);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'concurrency_limit_exceeded']], 429, ['Retry-After' => '5'])]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('provider_busy')
        ->hold_nano->toBe(0)
        ->charge_nano->toBeNull();
});

it('fails for free when Pixelforge refuses outright', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code, 'message' => 'No.']], $status)]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->hold_nano->toBe(0);
})->with([
    'content policy' => [400, 'content_policy_violation'],
    'daily quota' => [429, 'daily_limit_exceeded'],
    'validation' => [422, 'validation_failed'],
]);

it('re-sends a generation whose job never reached the queue (the Redis commit gap)', function () {
    Queue::fake();
    $stuck = Generation::factory()->create(['updated_at' => now()->subMinutes(3)]);
    $fresh = Generation::factory()->create();

    app()->call([new ReconcileGenerations, 'handle']);

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($stuck));
    Queue::assertNotPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($fresh));
});
