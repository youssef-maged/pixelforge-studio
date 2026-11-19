<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The balance ledger: purchases add, charges subtract. users.balance_nano is its running total.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // purchase | charge
            $table->bigInteger('amount_nano');
            $table->bigInteger('balance_after_nano');
            $table->foreignUuid('generation_id')->nullable()->unique()->constrained()->nullOnDelete(); // one charge per generation, ever
            $table->uuid('idempotency_key')->nullable(); // one purchase per "Buy" click, ever
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });

        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_shape CHECK (
            (type = 'purchase' AND amount_nano > 0 AND generation_id IS NULL AND idempotency_key IS NOT NULL)
            OR (type = 'charge' AND amount_nano < 0 AND generation_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
