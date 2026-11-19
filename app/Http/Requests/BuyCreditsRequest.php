<?php

namespace App\Http\Requests;

use App\Actions\BuyCredits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuyCreditsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pack' => ['required', 'integer', Rule::in(BuyCredits::PACKS)],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
