<?php

namespace App\Http\Controllers;

use App\Actions\StartGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function index(Request $request, PixelforgeClient $pixelforge): Response
    {
        $generations = $request->user()->generations();

        return Inertia::render('Studio', [
            'generations' => Inertia::scroll(fn () => GenerationResource::collection(
                $generations->clone()->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(18),
            )),
            // Polled every 2 s for the ids the page is watching (only those still in flight), until they settle.
            'watched' => Inertia::optional(fn () => GenerationResource::collection(
                $generations->clone()->whereIn('id', array_filter((array) $request->input('watch'), [Str::class, 'isUuid']))->get(),
            )),
            'pricing' => fn () => $pixelforge->pricing()->list,
        ]);
    }

    public function store(StoreGenerationRequest $request, StartGeneration $start): RedirectResponse
    {
        $start(
            $request->user(),
            $request->string('prompt')->value(),
            $request->string('size')->value(),
            $request->string('quality')->value(),
            $request->array('reference_images'),
            $request->string('client_request_id')->value(),
        );

        return to_route('studio');
    }
}
