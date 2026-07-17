<?php

namespace App\Services\Billing;

use App\Models\IntegrationReceipt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class IntegrationEventGuard
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): void  $callback
     */
    public function once(string $provider, array $payload, callable $callback): void
    {
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $eventId = (string) ($payload['id'] ?? hash('sha256', $encodedPayload));
        $payloadHash = hash('sha256', $encodedPayload);

        IntegrationReceipt::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => (string) ($payload['type'] ?? 'unknown'),
            'status' => 'processing',
            'payload_hash' => $payloadHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use (
                $provider,
                $eventId,
                $payloadHash,
                $payload,
                $callback,
            ): void {
                $receipt = IntegrationReceipt::query()
                    ->where('provider', $provider)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! hash_equals($receipt->payload_hash, $payloadHash)) {
                    throw new RuntimeException('Integration event ID was reused with a different payload.');
                }

                if ($receipt->status === 'processed') {
                    return;
                }

                $receipt->forceFill([
                    'status' => 'processing',
                    'error_message' => null,
                ])->save();

                $callback($payload);

                $receipt->forceFill([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ])->save();
            }, 3);
        } catch (Throwable $exception) {
            IntegrationReceipt::query()
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->whereNot('status', 'processed')
                ->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }
}
