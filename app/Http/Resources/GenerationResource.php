<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Nano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'in_flight' => ! $this->status->isTerminal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimate' => Nano::toDecimal($this->estimate_nano),
            'charge' => $this->charge_nano === null ? null : Nano::toDecimal($this->charge_nano),
            'provider_cost' => $this->provider_cost_nano === null ? null : Nano::toDecimal($this->provider_cost_nano),
            'usage' => $this->usage,
            'rates' => $this->rates,
            'image' => $this->imageSrc(),
            'error_code' => $this->error_code,
            'failure_reason' => $this->failureReason(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
