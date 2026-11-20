<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReferenceImageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pixelforge takes reference images as links only, so an upload is stored privately and
 * shared as a signed link that works for 7 days, long enough for any generation to finish.
 */
class ReferenceImageController extends Controller
{
    public function __invoke(StoreReferenceImageRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $disk = Storage::disk(config('filesystems.images'));
        $path = "references/{$request->user()->id}/".Str::uuid().'.'.$file->extension();

        $disk->put($path, $file->getContent())
            || throw new RuntimeException("Couldn't store the uploaded reference image.");

        return response()->json([
            'url' => $disk->temporaryUrl($path, now()->addDays(7)),
            'name' => $file->getClientOriginalName(),
        ], 201);
    }
}
