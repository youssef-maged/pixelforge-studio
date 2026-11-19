<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property int $user_id
 * @property string $client_request_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property array<string, string>|null $rates
 * @property int $estimate_nano
 * @property int $hold_nano
 * @property array<string, int>|null $usage
 * @property int|null $provider_cost_nano
 * @property int|null $charge_nano
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $image_path
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimate_nano' => 'integer',
            'hold_nano' => 'integer',
            'provider_cost_nano' => 'integer',
            'charge_nano' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A short-lived URL for our stored copy, or Pixelforge's own URL while it still works. */
    public function imageSrc(): ?string
    {
        if ($this->image_path) {
            return Storage::disk(config('filesystems.images'))->temporaryUrl($this->image_path, now()->addMinutes(30));
        }

        return $this->image_url_expires_at?->isFuture() ? $this->image_url : null;
    }

    /** What went wrong, in words a user can act on. Every failure is free. */
    public function failureReason(): ?string
    {
        return match ($this->error_code) {
            null => null,
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy.',
            'generation_failed' => "The model couldn't produce an image for this prompt. Rewording it may help.",
            'internal_error' => 'Pixelforge had an internal error. Trying again usually works.',
            'daily_limit_exceeded' => "Pixelforge's daily limit is used up. Try again after 00:00 UTC.",
            'provider_busy' => 'Pixelforge stayed at capacity for 15 minutes, so we stopped waiting.',
            'validation_failed' => 'Pixelforge refused the request: '.$this->error_message,
            default => 'Pixelforge could not take this request ('.$this->error_code.').',
        };
    }
}
