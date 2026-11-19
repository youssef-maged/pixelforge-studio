<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ReferenceImageController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

// Guests get the landing page; signed-in users go straight to the studio.
Route::inertia('/', 'Welcome')->middleware('guest')->name('home');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', 'studio')->name('dashboard');

    Route::get('studio', [StudioController::class, 'index'])->name('studio');
    Route::post('generations', [StudioController::class, 'store'])->middleware('throttle:generations')->name('generations.store');
    Route::post('reference-images', ReferenceImageController::class)->middleware('throttle:uploads')->name('reference-images.store');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits');
    Route::post('credits', [CreditsController::class, 'store'])->middleware('throttle:purchases')->name('credits.store');

    Route::middleware('can:view-admin-report')->group(function () {
        Route::get('admin/report', [ReportController::class, 'index'])->name('admin.report');
        Route::get('admin/report/export', [ReportController::class, 'export'])->name('admin.report.export');
        Route::get('admin/generations', [Admin\GenerationController::class, 'index'])->name('admin.generations');
        Route::get('admin/users', [Admin\UserController::class, 'index'])->name('admin.users');
        Route::get('admin/webhooks', [Admin\WebhookEventController::class, 'index'])->name('admin.webhooks');
    });
});

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
