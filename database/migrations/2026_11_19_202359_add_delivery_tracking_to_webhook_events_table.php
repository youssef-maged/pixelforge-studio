<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            // How often Pixelforge delivered this event (duplicates count up here; the event is still applied once).
            $table->unsignedSmallInteger('deliveries')->default(1);
            $table->timestamp('last_received_at')->nullable();
            $table->string('outcome')->nullable(); // what applying it did, for the operator log
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['deliveries', 'last_received_at', 'outcome']);
        });
    }
};
