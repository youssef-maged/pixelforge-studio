<?php

namespace App\Jobs;

use App\Actions\RecordGenerationState;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The safety net, run every minute: webhooks aren't guaranteed and workers can die.
 * Re-sends generations stuck before Pixelforge accepted them, retries image copies that never landed,
 * and polls generations that went quiet. One sweep at a time, and one bad row never stops the rest.
 */
class ReconcileGenerations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Pixelforge allows 60 requests a minute per account; leave most of them for real work. */
    private const MAX_POLLS_PER_RUN = 15;

    /** Stop polling well before the next sweep and the worker's job timeout. */
    private const POLL_BUDGET_SECONDS = 40;

    /** A sweep stuck past this frees the lock for the next one. */
    public int $uniqueFor = 120;

    public function handle(PixelforgeClient $pixelforge, RecordGenerationState $record): void
    {
        // Nobody has tried to submit it for 2 minutes: its queue message was lost. Same Idempotency-Key, so safe.
        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', now()->subMinutes(2))
            ->each(fn (Generation $generation) => SubmitGeneration::dispatch($generation));

        // Image copies that never landed; a copy job that gave up touches the row, so this waits 15 minutes.
        Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereNull('image_path')
            ->where('image_url_expires_at', '>', now())
            ->where('updated_at', '<', now()->subMinutes(15))
            ->each(fn (Generation $generation) => StoreGenerationImage::dispatch($generation));

        // Accepted but no webhook for a while: ask Pixelforge directly.
        $deadline = microtime(true) + self::POLL_BUDGET_SECONDS;
        $quiet = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->oldest('updated_at')
            ->limit(self::MAX_POLLS_PER_RUN)
            ->get();

        foreach ($quiet as $generation) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $response = $pixelforge->generation($generation->provider_id);
                if ($response->successful()) {
                    $record->apply($generation, $response->json());
                }
            } catch (Throwable $e) {
                report($e); // try the next one; this one goes to the back of the line below
            }

            $generation->touch();
        }
    }
}
