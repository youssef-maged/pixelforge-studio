<?php

namespace App\Http\Controllers;

use App\Actions\BuyCredits;
use App\Http\Requests\BuyCreditsRequest;
use App\Models\CreditTransaction;
use App\Support\Nano;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Credits', [
            'packs' => BuyCredits::PACKS,
            'transactions' => $request->user()->creditTransactions()
                ->with('generation:id,prompt')
                ->latest('id')
                ->paginate(25)
                ->through(fn (CreditTransaction $transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => Nano::toDecimal($transaction->amount_nano),
                    'balance_after' => Nano::toDecimal($transaction->balance_after_nano),
                    'prompt' => $transaction->generation?->prompt,
                    'created_at' => $transaction->created_at->toIso8601ZuluString(),
                ]),
        ]);
    }

    public function store(BuyCreditsRequest $request, BuyCredits $buy): RedirectResponse
    {
        $purchase = $buy($request->user(), $request->integer('pack'), $request->string('idempotency_key')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added $'.rtrim(rtrim(Nano::toDecimal($purchase->amount_nano), '0'), '.').' in credits.']);

        return back();
    }
}
