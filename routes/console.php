<?php

use App\Jobs\ReconcileGenerations;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// Safety net for missed webhooks and dead jobs; the job itself is unique, so sweeps never run side by side.
Schedule::job(new ReconcileGenerations)->everyMinute();

// Uploaded reference images are shared as 7-day links; after that the files serve no one.
Schedule::call(function () {
    $disk = Storage::disk(config('filesystems.images'));
    $cutoff = now()->subDays(7)->getTimestamp();

    foreach ($disk->allFiles('references') as $file) {
        if ($disk->lastModified($file) < $cutoff) {
            $disk->delete($file);
        }
    }
})->daily()->name('prune-reference-images')->withoutOverlapping()->onOneServer();
