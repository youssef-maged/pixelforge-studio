<?php

namespace App\Http\Requests;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(PixelforgeClient $pixelforge): array
    {
        $pricing = $pixelforge->pricing();

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https', 'distinct'],
            'client_request_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'Pixelforge accepts at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) link to an image.',
            'reference_images.*.distinct' => 'This image is already in the list. Pixelforge would bill it twice.',
        ];
    }
}
