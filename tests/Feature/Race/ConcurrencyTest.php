<?php

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// Real commits are needed so the other process can see them, hence truncation instead of a wrapping transaction.
uses(DatabaseTruncation::class);

/** Start the same operation in two processes at the same instant and wait for both. */
function race(array ...$commands): void
{
    $startAt = (string) (microtime(true) + 1.5); // both processes are booted before the starting gun
    $processes = array_map(function (array $args) use ($startAt) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/race.php'), $startAt, ...$args],
            env: ['QUEUE_CONNECTION' => 'null'],
        );
        $process->start();

        return $process;
    }, $commands);

    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    }
}

it('charges once when two success events for one generation land at the same moment', function () {
    $user = User::factory()->create(['balance_nano' => 1_000_000_000]);
    $generation = Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Processing, 'provider_id' => 'gen_race', 'rates' => pricingList()['rates'],
    ]);
    $object = json_encode(providerObject($generation, 'succeeded'));

    race(['apply', $generation->id, $object], ['apply', $generation->id, $object]);

    expect(CreditTransaction::where('generation_id', $generation->id)->count())->toBe(1)
        ->and($user->fresh()->balance_nano)->toBe(1_000_000_000 - 381_887_500);
});

it('never loses a purchase when two land at the same moment', function () {
    $user = User::factory()->create();

    race(['buy', $user->id, '5', (string) Str::uuid()], ['buy', $user->id, '10', (string) Str::uuid()]);

    expect($user->fresh()->balance_nano)->toBe(15_000_000_000)
        ->and(CreditTransaction::count())->toBe(2);
});

it('counts a double-clicked purchase once, even when both requests race', function () {
    $user = User::factory()->create();
    $key = (string) Str::uuid();

    race(['buy', $user->id, '5', $key], ['buy', $user->id, '5', $key]);

    expect($user->fresh()->balance_nano)->toBe(5_000_000_000)
        ->and(CreditTransaction::count())->toBe(1);
});
