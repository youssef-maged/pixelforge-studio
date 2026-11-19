<?php

namespace App\Http\Controllers;

use App\Actions\RecordGenerationState;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receives Pixelforge webhooks. Delivery is at least once and in any order, so:
 * the event id is recorded exactly once, in the same transaction that applies it. If applying fails,
 * nothing is recorded, we answer 500, and Pixelforge redelivers later. A duplicate is a no-op.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, RecordGenerationState $record): Response
    {
        $payload = $request->getContent();

        abort_unless($this->signatureIsValid($payload, (string) $request->header('Pixelforge-Signature')), 400, 'Invalid Pixelforge signature.');

        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if ($event['type'] === 'ping') {
            return response()->noContent();
        }

        $object = $event['data']['object'];
        $generation = $this->findGeneration($object);

        DB::transaction(function () use ($event, $object, $generation, $request, $record) {
            $isNew = WebhookEvent::query()->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'generation_id' => $generation?->id,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => json_encode($event),
                'received_at' => now(),
            ]) === 1;

            if ($isNew && $generation) {
                $record->apply($generation, $object);
            }
        });

        return response()->noContent();
    }

    /**
     * By our own id in metadata, which works even when the webhook beats our POST response.
     *
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        $ourId = $object['metadata']['generation_id'] ?? null;

        return (Str::isUuid($ourId) ? Generation::find($ourId) : null)
            ?? Generation::firstWhere('provider_id', $object['id'] ?? null);
    }

    /** `t=<unix>,v1=<hex hmac>`: HMAC-SHA256 of "<t>.<raw body>", constant-time compared, recent timestamps only. */
    private function signatureIsValid(string $payload, string $header): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            match ($key) {
                't' => $timestamp = ctype_digit($value) ? (int) $value : null,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        $secret = (string) config('services.pixelforge.webhook_secret');
        if ($timestamp === null || $secret === '' || abs(time() - $timestamp) > config('services.pixelforge.webhook_tolerance')) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
