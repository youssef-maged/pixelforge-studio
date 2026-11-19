<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type purchase|charge
 * @property int $amount_nano
 * @property int $balance_after_nano
 * @property string|null $generation_id
 * @property string|null $idempotency_key
 */
class CreditTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_nano' => 'integer', 'balance_after_nano' => 'integer'];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
