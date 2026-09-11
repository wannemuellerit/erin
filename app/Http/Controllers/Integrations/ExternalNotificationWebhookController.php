<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ExternalNotificationDelivery;
use App\Models\ExternalNotificationWebhookReceipt;
use App\Models\NotificationPhoneChannel;
use App\Models\NotificationPreference;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalNotificationWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        abort_unless($provider === 'twilio', 404);
        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('services.twilio.webhook_max_bytes', 262144), 413);
        abort_unless($this->validSignature($request), 401);
        $messageId = (string) ($request->input('MessageSid') ?? $request->input('SmsSid') ?? '');
        $status = strtolower((string) ($request->input('MessageStatus') ?? $request->input('SmsStatus') ?? 'received'));
        $eventId = (string) ($request->header('I-Twilio-Idempotency-Token') ?: hash('sha256', $messageId.'|'.$status.'|'.(string) $request->input('Timestamp', '')));
        abort_if($messageId === '' || mb_strlen($messageId) > 160 || mb_strlen($eventId) > 160, 422);
        $payloadHash = hash('sha256', $body);

        try {
            ExternalNotificationWebhookReceipt::query()->create([
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'event_type' => $status,
                'payload_hash' => $payloadHash,
                'processed_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                $storedHash = ExternalNotificationWebhookReceipt::query()
                    ->where('provider', $provider)
                    ->where('provider_event_id', $eventId)
                    ->value('payload_hash');
                abort_unless(is_string($storedHash) && hash_equals($storedHash, $payloadHash), 409);

                return response()->json(['received' => true, 'duplicate' => true]);
            }

            throw $exception;
        }

        DB::transaction(function () use ($request, $messageId, $status): void {
            $delivery = ExternalNotificationDelivery::query()
                ->where('provider', 'twilio')
                ->where('provider_message_id', $messageId)
                ->lockForUpdate()
                ->first();
            if ($delivery !== null) {
                $mapped = match ($status) {
                    'delivered', 'read' => 'delivered',
                    'failed', 'undelivered' => 'failed',
                    'sent', 'queued', 'accepted' => 'sent',
                    default => $delivery->status,
                };
                $delivery->update([
                    'status' => $mapped,
                    'delivered_at' => $mapped === 'delivered' ? now() : $delivery->delivered_at,
                    'failed_at' => $mapped === 'failed' ? now() : $delivery->failed_at,
                    'failure_code' => $mapped === 'failed' ? (string) $request->input('ErrorCode', 'provider_failed') : null,
                ]);
            }

            $body = strtoupper(trim((string) $request->input('Body', '')));
            if (in_array($body, ['STOP', 'STOPP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
                $phone = preg_replace('/^whatsapp:/', '', (string) $request->input('From', '')) ?? '';
                $channels = NotificationPhoneChannel::query()
                    ->where('phone_hash', hash('sha256', $phone))
                    ->get();
                foreach ($channels as $channel) {
                    $channel->update(['revoked_at' => now(), 'consented_at' => null]);
                    $column = $channel->channel === 'whatsapp' ? 'whatsapp_enabled' : 'sms_enabled';
                    NotificationPreference::query()->where('user_id', $channel->user_id)->update([$column => false]);
                }
            }
        });

        return response()->json(['received' => true]);
    }

    private function validSignature(Request $request): bool
    {
        $secret = (string) config('services.twilio.webhook_secret');
        $hmac = (string) $request->header('X-Erin-Signature');
        if (strlen($secret) >= 32 && $hmac !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $hmac)) {
            return true;
        }

        $authToken = (string) config('services.twilio.auth_token');
        $twilioSignature = (string) $request->header('X-Twilio-Signature');
        if ($authToken === '' || $twilioSignature === '') {
            return false;
        }
        $data = $request->post();
        ksort($data);
        $signed = $request->fullUrl();
        foreach ($data as $key => $value) {
            $signed .= $key.(is_scalar($value) ? (string) $value : '');
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $signed, $authToken, true)), $twilioSignature);
    }
}
