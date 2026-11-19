<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Keeps our own copy of a finished image; Pixelforge's signed URL dies 24 hours after completion. */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public Generation $generation) {}

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

        $png = Http::timeout(30)->get($generation->image_url)->throw()->body();
        $path = "generations/{$generation->id}.png";

        Storage::disk(config('filesystems.images'))->put($path, $png);
        $generation->update(['image_path' => $path]);
    }
}
