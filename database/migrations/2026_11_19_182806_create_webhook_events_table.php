<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every Pixelforge event we acted on, exactly once (the primary key is Pixelforge's event id).
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('delivery_attempt')->nullable();
            $table->jsonb('payload');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
