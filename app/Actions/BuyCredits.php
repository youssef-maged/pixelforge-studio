<?php

namespace App\Actions;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Nano;
use Illuminate\Support\Facades\DB;

/** A fake purchase. The idempotency key makes a double-click (or a retried request) buy once. */
class BuyCredits
{
    public const PACKS = [5, 10, 25];

    public function __invoke(User $user, int $dollars, string $idempotencyKey): CreditTransaction
    {
        return DB::transaction(function () use ($user, $dollars, $idempotencyKey) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $existing = $user->creditTransactions()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $amount = Nano::fromDecimal((string) $dollars);
            $user->balance_nano += $amount;
            $user->save();

            return $user->creditTransactions()->create([
                'type' => 'purchase',
                'amount_nano' => $amount,
                'balance_after_nano' => $user->balance_nano,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }
}
