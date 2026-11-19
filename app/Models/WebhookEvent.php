<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id Pixelforge's event id (evt_…)
 * @property string $type
 * @property string|null $generation_id
 * @property int|null $delivery_attempt
 * @property array<string, mixed> $payload
 * @property int $deliveries
 * @property string|null $outcome
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $last_received_at
 */
class WebhookEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'last_received_at' => 'datetime', 'deliveries' => 'integer'];
    }
}
