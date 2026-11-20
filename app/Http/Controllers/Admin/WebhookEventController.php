<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Every Pixelforge event we received: how often it was delivered, and what applying it (once) did. */
class WebhookEventController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'duplicates' => ['nullable', 'boolean'],
        ]);

        $events = WebhookEvent::query()
            ->when(filled($search = $filters['q'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('id', Like::contains($search))->orWhereLike('type', Like::contains($search))->orWhereRaw('generation_id::text ilike ?', [Like::contains($search)])))
            ->when($request->boolean('duplicates'), fn (Builder $query) => $query->where('deliveries', '>', 1))
            ->orderByDesc('received_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (WebhookEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'generation_id' => $event->generation_id,
                'deliveries' => $event->deliveries,
                'outcome' => $event->outcome,
                'received_at' => $event->received_at->toIso8601ZuluString(),
            ]);

        return Inertia::render('Admin/Webhooks', [
            'filters' => ['q' => $filters['q'] ?? null, 'duplicates' => $request->boolean('duplicates')],
            'events' => $events,
            'stats' => [
                'events' => WebhookEvent::count(),
                'duplicates' => (int) WebhookEvent::query()->sum(DB::raw('deliveries - 1')),
            ],
        ]);
    }
}
