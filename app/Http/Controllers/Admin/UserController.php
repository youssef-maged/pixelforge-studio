<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Nano;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Balances, holds, purchases and spend per user. */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        $users = User::query()
            ->when($search, fn (Builder $query, string $search) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%"))
            ->withSum(['generations as held_nano' => fn (Builder $query) => $query->whereIn('status', GenerationStatus::inFlight())], 'hold_nano')
            ->withSum(['creditTransactions as purchased_nano' => fn (Builder $query) => $query->where('type', 'purchase')], 'amount_nano')
            ->withSum(['creditTransactions as spent_nano' => fn (Builder $query) => $query->where('type', 'charge')], 'amount_nano')
            ->withCount(['generations', 'generations as in_flight_count' => fn (Builder $query) => $query->whereIn('status', GenerationStatus::inFlight())])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'balance' => Nano::toDecimal($user->balance_nano),
                'held' => Nano::toDecimal((int) $user->getAttribute('held_nano')),
                'available' => Nano::toDecimal($user->balance_nano - (int) $user->getAttribute('held_nano')),
                'purchased' => Nano::toDecimal((int) $user->getAttribute('purchased_nano')),
                'spent' => Nano::toDecimal(-(int) $user->getAttribute('spent_nano')),
                'generations' => (int) $user->getAttribute('generations_count'),
                'in_flight' => (int) $user->getAttribute('in_flight_count'),
                'joined_at' => $user->created_at?->toIso8601ZuluString(),
            ]);

        return Inertia::render('Admin/Users', ['filters' => ['q' => $search], 'users' => $users]);
    }
}
