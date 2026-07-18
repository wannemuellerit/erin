<?php

namespace App\Services\Billing;

use App\Models\IntegrationReceipt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class StripeWebhookEventProcessor
{
    /**
     * Process one exact Stripe event once without holding a database
     * transaction open while Stripe's API is queried.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): void  $callback
     */
    public function once(array $payload, callable $callback): void
    {
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $eventId = $payload['id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            throw new RuntimeException('Das Stripe-Ereignis besitzt keine gültige ID.');
        }
        $payloadHash = hash('sha256', $encodedPayload);
        $lock = Cache::lock(
            'stripe-webhook-event:'.hash('sha256', $eventId),
            120,
        );

        try {
            $lock->block(15, function () use (
                $eventId,
                $payload,
                $payloadHash,
                $callback,
            ): void {
                IntegrationReceipt::query()->insertOrIgnore([
                    'provider' => 'stripe:handled',
                    'event_id' => $eventId,
                    'event_type' => (string) ($payload['type'] ?? 'unknown'),
                    'status' => 'processing',
                    'payload_hash' => $payloadHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $receipt = IntegrationReceipt::query()
                    ->where('provider', 'stripe:handled')
                    ->where('event_id', $eventId)
                    ->firstOrFail();

                if (! hash_equals($receipt->payload_hash, $payloadHash)) {
                    throw new RuntimeException(
                        'Integration event ID was reused with a different payload.',
                    );
                }

                if ($receipt->status === 'processed') {
                    return;
                }

                $receipt->forceFill([
                    'status' => 'processing',
                    'error_message' => null,
                ])->save();

                try {
                    $callback($payload);
                } catch (Throwable $exception) {
                    $receipt->forceFill([
                        'status' => 'failed',
                        'error_message' => 'Die Stripe-Verarbeitung ist fehlgeschlagen; technische Details stehen ausschließlich im geschützten Anwendungslog.',
                    ])->save();

                    throw $exception;
                }

                $receipt->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ])->save();
            });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Das Stripe-Ereignis wird bereits verarbeitet.',
                previous: $exception,
            );
        }
    }
}
