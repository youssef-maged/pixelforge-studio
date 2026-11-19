<?php

use App\Jobs\ReconcileGenerations;
use Illuminate\Support\Facades\Schedule;

// Safety net for missed webhooks and dead jobs; see ReconcileGenerations.
Schedule::job(new ReconcileGenerations)->everyMinute()->withoutOverlapping();
