<?php

use App\Actions\BuyCredits;
use App\Actions\RecordGenerationState;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

/*
 * Runs one money operation in its own PHP process, starting at an agreed instant,
 * so tests/Feature/Race can fire two of them at Postgres at the same time.
 * Usage: php race.php <start-unix-float> apply <generation-id> <object-json>
 *        php race.php <start-unix-float> buy <user-id> <dollars> <idempotency-key>
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $startAt, $operation] = $argv;
time_sleep_until((float) $startAt);

match ($operation) {
    'apply' => app(RecordGenerationState::class)
        ->apply(Generation::findOrFail($argv[3]), json_decode($argv[4], true)),
    'buy' => app(BuyCredits::class)(User::findOrFail($argv[3]), (int) $argv[4], $argv[5]),
};
