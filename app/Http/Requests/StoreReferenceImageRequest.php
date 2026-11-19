<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferenceImageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `image` checks the real file contents (not just the extension) and excludes SVG.
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240', 'dimensions:min_width=64,min_height=64,max_width=8192,max_height=8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.image' => "That file isn't an image we can read.",
            'image.mimes' => 'Use a PNG, JPEG or WebP image.',
            'image.max' => 'Images can be at most 10 MB.',
            'image.dimensions' => 'Images must be between 64×64 and 8192×8192 pixels.',
            'image.uploaded' => "The upload didn't finish. Check your connection and try again.",
        ];
    }
}
