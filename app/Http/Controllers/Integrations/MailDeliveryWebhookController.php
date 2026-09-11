<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Mail\EmailSuppressionService;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MailDeliveryWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        EmailSuppressionService $suppressions,
    ): JsonResponse {
        abort_unless(in_array($provider, ['postmark', 'resend'], true), 404);
        $secret = (string) config('services.mail_delivery.webhook_secret');
        abort_if(strlen($secret) < 32, 503);
        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('services.mail_delivery.webhook_max_bytes', 262144), 413);
        abort_unless(hash_equals(
            hash_hmac('sha256', $body, $secret),
            (string) $request->header('X-Erin-Mail-Signature'),
        ), 401);
        $payload = $request->json()->all();
        $event = $this->normalize($provider, $payload);

        $created = DB::table('email_delivery_events')->insertOrIgnore([
            'provider' => $provider,
            'provider_event_id' => $event['id'],
            'notification_delivery_id' => $event['delivery_id'],
            'recipient_hash' => $suppressions->hash($event['email']),
            'event_type' => $event['type'],
            'payload_hash' => hash('sha256', $body),
            'occurred_at' => $event['occurred_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($created === 0) {
            $storedHash = DB::table('email_delivery_events')
                ->where('provider', $provider)
                ->where('provider_event_id', $event['id'])
                ->value('payload_hash');
            abort_unless(is_string($storedHash) && hash_equals($storedHash, hash('sha256', $body)), 409);

            return response()->json(['accepted' => true, 'duplicate' => true]);
        }

        if ($event['delivery_id'] !== null) {
            NotificationDelivery::query()->whereKey($event['delivery_id'])->update([
                'status' => in_array($event['type'], ['hard_bounce', 'complaint'], true) ? 'failed' : $event['type'],
                'failed_at' => in_array($event['type'], ['hard_bounce', 'complaint'], true) ? now() : null,
                'failure_channel' => in_array($event['type'], ['hard_bounce', 'complaint'], true) ? 'mail' : null,
                'failure_code' => in_array($event['type'], ['hard_bounce', 'complaint'], true) ? $event['type'] : null,
            ]);
        }

        if (in_array($event['type'], ['hard_bounce', 'complaint'], true)) {
            $suppressions->suppress($event['email'], $event['type'], $provider);
            $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($event['email'])])->first();
            if ($user !== null) {
                app(ProductNotificationDispatcher::class)->dispatch(
                    $user,
                    'system.email_suppressed',
                    "mail-suppressed:{$event['id']}",
                    [
                        'title' => __('E-Mail-Zustellung pausiert'),
                        'message' => __('E-Mails an deine Adresse wurden nach einem Zustellfehler pausiert. Bitte prüfe deine Adresse oder kontaktiere den Support.'),
                        'translations' => [
                            'de' => [
                                'title' => 'E-Mail-Zustellung pausiert',
                                'message' => 'E-Mails an deine Adresse wurden nach einem Zustellfehler pausiert. Bitte prüfe deine Adresse oder kontaktiere den Support.',
                            ],
                            'en' => [
                                'title' => 'Email delivery paused',
                                'message' => 'Emails to your address were paused after a delivery error. Please check your address or contact support.',
                            ],
                        ],
                        'url' => route('profile.edit'),
                    ],
                );
            }
        }

        return response()->json(['accepted' => true], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, type: string, email: string, delivery_id: int|null, occurred_at: \DateTimeInterface}
     */
    private function normalize(string $provider, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $metadata = is_array($payload['Metadata'] ?? null)
            ? $payload['Metadata']
            : (is_array($data['metadata'] ?? null) ? $data['metadata'] : []);
        $rawType = mb_strtolower((string) ($payload['RecordType'] ?? $payload['type'] ?? 'unknown'));
        $type = match (true) {
            str_contains($rawType, 'spam'), str_contains($rawType, 'complaint') => 'complaint',
            str_contains($rawType, 'hardbounce'), str_contains($rawType, 'hard_bounce') => 'hard_bounce',
            str_contains($rawType, 'bounce') => 'soft_bounce',
            str_contains($rawType, 'deliver') => 'delivered',
            default => 'unknown',
        };
        $recipient = $payload['Email'] ?? $data['to'] ?? $data['email'] ?? '';
        $email = is_array($recipient) ? (string) ($recipient[0] ?? '') : (string) $recipient;
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 422);
        $id = (string) ($payload['MessageID'] ?? $payload['ID'] ?? $data['email_id'] ?? '');
        abort_if($id === '' || mb_strlen($id) > 190, 422);
        $deliveryId = filter_var(
            $metadata['notification_delivery_id'] ?? null,
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE,
        );

        return [
            'id' => $id,
            'type' => $type,
            'email' => $email,
            'delivery_id' => is_int($deliveryId) ? $deliveryId : null,
            'occurred_at' => now(),
        ];
    }
}
