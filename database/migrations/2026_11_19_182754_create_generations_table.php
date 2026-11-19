<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our id doubles as Pixelforge's Idempotency-Key and as metadata.generation_id.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_request_id'); // one generation per form submit, even on double-click
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->json('rates')->nullable(); // fixed by Pixelforge when it accepts the generation

            // Money, in integer nanodollars (1e-9 USD).
            $table->bigInteger('estimate_nano'); // the "up to" price shown before submitting
            $table->bigInteger('hold_nano')->default(0); // reserved from the balance while in flight
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable(); // what Pixelforge charges us
            $table->bigInteger('charge_nano')->nullable(); // what we charge the user (cost × 1.25)

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index(); // Pixelforge's, so report days match its billing days
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id']);
        });

        // Rails the database enforces even if application code is wrong.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_status_valid
            CHECK (status IN ('pending', 'queued', 'processing', 'succeeded', 'failed'))");
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_money_non_negative
            CHECK (estimate_nano >= 0 AND hold_nano >= 0 AND COALESCE(provider_cost_nano, 0) >= 0 AND COALESCE(charge_nano, 0) >= 0)');
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_succeeded_is_priced
            CHECK (status <> 'succeeded' OR (usage IS NOT NULL AND provider_cost_nano IS NOT NULL AND charge_nano IS NOT NULL))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_failed_is_free
            CHECK (status <> 'failed' OR (charge_nano IS NULL AND hold_nano = 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
