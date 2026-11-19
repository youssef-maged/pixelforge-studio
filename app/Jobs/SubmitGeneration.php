<?php

namespace App\Jobs;

use App\Actions\RecordGenerationState;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Hands a pending generation to Pixelforge, politely waiting out its limits.
 *
 * Every attempt sends the same Idempotency-Key (our generation id), so a retry can only ever return the
 * generation Pixelforge already has, never create a second one. That makes "unknown outcome" (a timeout)
 * safe to retry, and it is the only kind of failure we never give up on locally.
 */
class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How long a user's request may wait for a free Pixelforge slot before we stop, free of charge. */
    public const PATIENCE_MINUTES = 15;

    /** Codes that mean "nothing was created, try again later". */
    private const TRY_LATER = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public int $uniqueFor = 1200;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    /**
     * Releases count as attempts, so bound the job by time instead. Evaluated at dispatch, so a job
     * re-dispatched by ReconcileGenerations gets a fresh window; patience itself is measured from created_at.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::PATIENCE_MINUTES + 10);
    }

    public function handle(PixelforgeClient $pixelforge, RecordGenerationState $record): void
    {
        $generation = $this->generation->fresh();
        if ($generation->status !== GenerationStatus::Pending) {
            return; // already accepted (a webhook beat us here) or already given up
        }

        try {
            $response = $pixelforge->createGeneration($generation);
        } catch (ConnectionException) {
            $this->release($this->nextDelay());

            return; // unknown outcome: retry with the same key, which is always safe
        }

        if ($response->status() === 202) {
            $record->apply($generation, $response->json());

            return;
        }

        $code = (string) $response->json('error.code', 'http_'.$response->status());

        if (in_array($code, self::TRY_LATER, true) || $response->serverError()) {
            if ($response->serverError() && $code !== 'overloaded') {
                $this->release($this->nextDelay()); // an unexplained 5xx is an unknown outcome too

                return;
            }

            if ($generation->created_at->diffInMinutes(now()) >= self::PATIENCE_MINUTES) {
                $record->reject($generation, 'provider_busy');

                return;
            }

            $this->release(max((int) $response->header('Retry-After'), $this->nextDelay()));

            return;
        }

        // Anything else is a definite "no" (content policy, daily quota, validation, auth): nothing was created.
        if ($code === 'invalid_api_key') {
            report(new \RuntimeException('Pixelforge rejected our API key.'));
        }

        $record->reject($generation, $code, $response->json('error.message'));
    }

    /** 2 s, 4 s, 8 s … capped at a minute, plus jitter so queued users don't knock in lockstep. */
    private function nextDelay(): int
    {
        return min(60, 2 ** min($this->attempts(), 6)) + random_int(0, 2);
    }
}
