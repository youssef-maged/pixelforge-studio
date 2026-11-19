<?php

namespace App\Jobs;

use App\Actions\RecordGenerationState;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The safety net, run every minute: webhooks aren't guaranteed and workers can die.
 * Polls generations that went quiet, re-sends ones stuck before Pixelforge accepted them,
 * and retries image copies that never landed.
 */
class ReconcileGenerations implements ShouldQueue
{
    use Queueable;

    /** Pixelforge allows 60 requests a minute per account; leave most of them for real work. */
    private const MAX_POLLS_PER_RUN = 15;

    public function handle(PixelforgeClient $pixelforge, RecordGenerationState $record): void
    {
        // A submit job that died: re-dispatching is safe (same Idempotency-Key; ShouldBeUnique stops doubles).
        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', now()->subMinutes(2))
            ->each(fn (Generation $generation) => SubmitGeneration::dispatch($generation));

        // Accepted but no webhook for a while: ask Pixelforge directly.
        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->oldest('updated_at')
            ->limit(self::MAX_POLLS_PER_RUN)
            ->get()
            ->each(function (Generation $generation) use ($pixelforge, $record) {
                $response = $pixelforge->generation($generation->provider_id);
                if ($response->successful()) {
                    $record->apply($generation, $response->json());
                }
                $generation->touch(); // go to the back of the polling line
            });

        Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereNull('image_path')
            ->where('image_url_expires_at', '>', now())
            ->where('completed_at', '<', now()->subMinutes(5))
            ->each(fn (Generation $generation) => StoreGenerationImage::dispatch($generation));
    }
}
