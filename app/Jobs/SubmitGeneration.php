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
 * safe to retry, and once an outcome was unknown we never give up locally: Pixelforge may already have it.
 */
class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How long a user's request may wait for a free Pixelforge slot before we stop, free of charge. */
    public const PATIENCE_MINUTES = 15;

    /** Codes that mean "nothing was created, try again later". */
    private const TRY_LATER = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    /**
     * Just above the longest wait between attempts. Each attempt touches the generation, so the sweep only
     * re-dispatches one nobody has tried for 2 minutes, i.e. one whose queue message was really lost.
     */
    public int $uniqueFor = 150;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    /** Releases count as attempts, so bound each dispatch by time; the sweep re-dispatches if it runs out. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::PATIENCE_MINUTES + 10);
    }

    /**
     * Waits after an unexpected exception (e.g. a deadlock, or pricing unreachable while applying a 202),
     * so a crash can't turn into a tight loop of POSTs eating the shared rate limit.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(PixelforgeClient $pixelforge, RecordGenerationState $record): void
    {
        $generation = $this->generation->fresh();
        if ($generation->status !== GenerationStatus::Pending) {
            return; // already accepted (a webhook beat us here) or already given up
        }

        $generation->submit_started_at ??= now();
        $generation->touch(); // saves submit_started_at too; tells the sweep this generation is being worked on

        try {
            $response = $pixelforge->createGeneration($generation);
        } catch (ConnectionException) {
            $this->unknownOutcome($generation);

            return;
        }

        if ($response->status() === 202) {
            $record->apply($generation, $response->json());

            return;
        }

        $code = (string) $response->json('error.code', 'http_'.$response->status());

        if ($response->serverError() && $code !== 'overloaded') {
            $this->unknownOutcome($generation); // an unexplained 5xx is an unknown outcome too

            return;
        }

        if (in_array($code, self::TRY_LATER, true)) {
            // "Busy" doesn't prove an earlier, unanswered attempt wasn't created, so after one we keep asking.
            $patienceUsed = $generation->submit_started_at->diffInMinutes(now()) >= self::PATIENCE_MINUTES;
            if ($patienceUsed && ! $generation->outcome_unknown) {
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

    /** Pixelforge may or may not have it: remember that, and re-ask with the same key. */
    private function unknownOutcome(Generation $generation): void
    {
        $generation->forceFill(['outcome_unknown' => true])->save();
        $this->release($this->nextDelay());
    }

    /** 2 s, 4 s, 8 s … capped at a minute, plus jitter so queued users don't knock in lockstep. */
    private function nextDelay(): int
    {
        return min(60, 2 ** min($this->attempts(), 6)) + random_int(0, 2);
    }
}
