<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Keeps our own copy of a finished image; Pixelforge's signed URL dies 24 hours after completion.
 * image_path is only written once the copy really landed, so a failed save keeps showing Pixelforge's URL
 * and the sweep tries again later.
 */
class StoreGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    /** Covers all retries, so the sweep can't stack a second copy job on top of a running one. */
    public int $uniqueFor = 900;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(): void
    {
        $generation = $this->generation->fresh();
        if ($generation->image_path || ! $generation->image_url || ! $generation->image_url_expires_at?->isFuture()) {
            return;
        }

        // Only ever fetch from Pixelforge itself (the URL arrives in a signed webhook, but stay strict).
        $pixelforgeHost = parse_url(config('services.pixelforge.url'), PHP_URL_HOST);
        if (parse_url($generation->image_url, PHP_URL_HOST) !== $pixelforgeHost) {
            return;
        }

        try {
            $png = Http::timeout(30)->get($generation->image_url)->throw()->body();
        } catch (RequestException $e) {
            if ($e->response->clientError()) {
                $this->fail($e); // gone or forbidden: retrying won't help

                return;
            }
            throw $e;
        }

        $path = "generations/{$generation->id}.png";
        Storage::disk(config('filesystems.images'))->put($path, $png)
            || throw new RuntimeException("Couldn't save the image copy for generation {$generation->id}.");

        $generation->update(['image_path' => $path]);
    }

    /** Out of tries: note it, and make the sweep wait a while before trying again. */
    public function failed(?Throwable $e): void
    {
        Log::warning('Gave up copying a generation image for now.', ['generation' => $this->generation->id, 'reason' => $e?->getMessage()]);
        $this->generation->touch();
    }
}
