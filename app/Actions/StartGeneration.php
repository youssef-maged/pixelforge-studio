<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Support\Nano;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a generation, reserves its "up to" price from the user's balance, and queues the call to Pixelforge.
 * The call itself happens in SubmitGeneration, so the user never waits on Pixelforge's limits.
 */
class StartGeneration
{
    public const MAX_IN_FLIGHT_PER_USER = 3;

    public function __construct(private PixelforgeClient $pixelforge) {}

    /** @param array<array-key, string> $referenceImages */
    public function __invoke(User $user, string $prompt, string $size, string $quality, array $referenceImages, string $clientRequestId): Generation
    {
        $references = array_values($referenceImages);
        $estimate = $this->pixelforge->pricing(fresh: true)->maxUserPriceNano($prompt, $size, $quality, count($references));

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $references, $clientRequestId, $estimate) {
            // Every money path locks the user row first, so balance checks never race.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $existing = $user->generations()->where('client_request_id', $clientRequestId)->first();
            if ($existing) {
                return $existing; // the same form submitted twice
            }

            if ($user->inFlightGenerations()->count() >= self::MAX_IN_FLIGHT_PER_USER) {
                throw ValidationException::withMessages([
                    'prompt' => 'You already have '.self::MAX_IN_FLIGHT_PER_USER.' generations in progress. Wait for one to finish.',
                ]);
            }

            $available = $user->balance_nano - $user->heldNano();
            if ($available < $estimate) {
                throw ValidationException::withMessages([
                    'credits' => $user->balance_nano < 0
                        ? 'Your balance is below zero because Pixelforge raised its prices while a generation was running. Buy credits to continue.'
                        : 'This costs up to $'.Nano::toDecimal($estimate).' and you have $'.Nano::toDecimal(max($available, 0)).' available.',
                ]);
            }

            $generation = $user->generations()->create([
                'client_request_id' => $clientRequestId,
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $references,
                'estimate_nano' => $estimate,
                'hold_nano' => $estimate,
            ]);

            // The job row commits with the generation (database queue), so neither can exist without the other.
            SubmitGeneration::dispatch($generation)->afterCommit();

            return $generation;
        });
    }
}
