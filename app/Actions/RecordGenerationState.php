<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use App\Support\Nano;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a generation's state, and the user's money, ever changes.
 *
 * Every source of truth goes through apply(): the POST response, an idempotent replay, each webhook, each poll.
 * A status only moves to a higher rank, so duplicates and late events are no-ops. Moving into succeeded or
 * failed therefore happens exactly once, and that is the only moment a user is charged (or released).
 */
class RecordGenerationState
{
    public function __construct(private PixelforgeClient $pixelforge) {}

    /** @param array<string, mixed> $object a Pixelforge generation object */
    public function apply(Generation $generation, array $object): Generation
    {
        return $this->locked($generation, function (Generation $generation, User $user) use ($object) {
            $incoming = GenerationStatus::from($object['status']);

            // If we gave up before Pixelforge ever accepted it, but Pixelforge did, Pixelforge's word wins:
            // otherwise it would bill us for a generation we never charged for.
            $adopting = $generation->status === GenerationStatus::Failed && $generation->provider_id === null;

            if (! $adopting && $incoming->rank() <= $generation->status->rank()) {
                $generation->provider_id ??= $object['id'];
                $generation->save();

                return $generation;
            }

            if ($adopting) {
                $generation->fill(['error_code' => null, 'error_message' => null, 'hold_nano' => $generation->estimate_nano]);
            }

            $generation->provider_id ??= $object['id'];
            $generation->status = $incoming;
            $generation->started_at ??= self::time($object['started_at'] ?? null);

            if ($generation->rates === null && isset($object['rates'])) {
                $generation->rates = $object['rates'];
                $this->reholdAtFixedRates($generation);
            }

            match ($incoming) {
                GenerationStatus::Succeeded => $this->charge($generation, $user, $object),
                GenerationStatus::Failed => $generation->fill([
                    'hold_nano' => 0,
                    'error_code' => $object['error']['code'] ?? 'generation_failed',
                    'error_message' => $object['error']['message'] ?? null,
                    'completed_at' => self::time($object['completed_at'] ?? null) ?? now(),
                ]),
                default => null,
            };

            $generation->save();

            return $generation;
        });
    }

    /** Pixelforge refused it outright (nothing was created there), or we stopped waiting. Free. */
    public function reject(Generation $generation, string $code, ?string $message = null): Generation
    {
        return $this->locked($generation, function (Generation $generation) use ($code, $message) {
            if ($generation->status === GenerationStatus::Pending) {
                $generation->fill([
                    'status' => GenerationStatus::Failed,
                    'hold_nano' => 0,
                    'error_code' => $code,
                    'error_message' => $message,
                    'completed_at' => now(),
                ])->save();
            }

            return $generation;
        });
    }

    /** @param array<string, mixed> $object */
    private function charge(Generation $generation, User $user, array $object): void
    {
        $providerCost = Pricing::costNano($object['usage'], $generation->rates);
        $charge = Nano::withMarkup($providerCost);

        $generation->fill([
            'usage' => $object['usage'],
            'provider_cost_nano' => $providerCost,
            'charge_nano' => $charge,
            'hold_nano' => 0,
            'image_url' => $object['image_url'] ?? null,
            'image_url_expires_at' => self::time($object['image_url_expires_at'] ?? null),
            'completed_at' => self::time($object['completed_at'] ?? null) ?? now(),
        ]);

        // Charged in full even if Pixelforge's fixed rates pushed it above the hold (balance may dip below zero).
        $user->balance_nano -= $charge;
        $user->save();

        // Unique on generation_id: a second charge for the same generation is impossible at the database level.
        $user->creditTransactions()->create([
            'type' => 'charge',
            'amount_nano' => -$charge,
            'balance_after_nano' => $user->balance_nano,
            'generation_id' => $generation->id,
        ]);

        StoreGenerationImage::dispatch($generation)->afterCommit();
    }

    /** Pixelforge fixes the rates on acceptance; if they rose since we priced it, hold the new maximum. */
    private function reholdAtFixedRates(Generation $generation): void
    {
        $max = $this->pixelforge->pricing()->maxUserPriceNano(
            $generation->prompt, $generation->size, $generation->quality, count($generation->reference_images), $generation->rates,
        );

        $generation->hold_nano = max($generation->hold_nano, $max);
    }

    /** Lock order is always user, then generation, everywhere money moves, so concurrent writers can't deadlock. */
    private function locked(Generation $generation, callable $callback): Generation
    {
        return DB::transaction(function () use ($generation, $callback) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $fresh = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            return $callback($fresh, $user);
        });
    }

    private static function time(?string $iso): ?Carbon
    {
        return $iso ? Carbon::parse($iso)->utc() : null;
    }
}
