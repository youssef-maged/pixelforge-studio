<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Support\Like;
use App\Support\Nano;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Every user's generations, for answering "why was I charged this?". */
class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(GenerationStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(filled($search = $filters['q'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('prompt', Like::contains($search))
                ->orWhereLike('provider_id', Like::contains($search))
                ->orWhereHas('user', fn (Builder $query) => $query->whereLike('name', Like::contains($search))->orWhereLike('email', Like::contains($search)))))
            ->latest()
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Generation $generation) => [
                'id' => $generation->id,
                'user' => $generation->user->only('id', 'name', 'email'),
                'prompt' => $generation->prompt,
                'spec' => "{$generation->size} · {$generation->quality}".($generation->reference_images ? ' · '.count($generation->reference_images).' ref' : ''),
                'status' => $generation->status,
                'provider_id' => $generation->provider_id,
                'estimate' => Nano::toDecimal($generation->estimate_nano),
                'charge' => $generation->charge_nano === null ? null : Nano::toDecimal($generation->charge_nano),
                'provider_cost' => $generation->provider_cost_nano === null ? null : Nano::toDecimal($generation->provider_cost_nano),
                'error_code' => $generation->error_code,
                'failure_reason' => $generation->failureReason(),
                'created_at' => $generation->created_at->toIso8601ZuluString(),
                'completed_at' => $generation->completed_at?->toIso8601ZuluString(),
            ]);

        return Inertia::render('Admin/Generations', [
            'filters' => ['status' => $filters['status'] ?? null, 'q' => $filters['q'] ?? null],
            'generations' => $generations,
            'counts' => Generation::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }
}
