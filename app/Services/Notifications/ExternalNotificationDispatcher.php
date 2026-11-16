<?php

namespace App\Services\Notifications;

use App\Jobs\SendExternalNotification;
use App\Models\ExternalNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\QueryException;

final readonly class ExternalNotificationDispatcher
{
    public function __construct(private ExternalNotificationPolicy $policy) {}

    public function queue(User $user, string $event, string $businessKey, NotificationDelivery $parent): void
    {
        if (! in_array($event, ExternalNotificationPolicy::ALLOWED_EVENTS, true)) {
            return;
        }

        foreach (['sms', 'whatsapp'] as $channel) {
            if (! $this->policy->preferenceEnabled($user, $event, $channel)) {
                continue;
            }

            try {
                $delivery = ExternalNotificationDelivery::query()->create([
                    'notification_delivery_id' => $parent->getKey(),
                    'user_id' => $user->getKey(),
                    'channel' => $channel,
                    'event' => $event,
                    'idempotency_key' => hash('sha256', $businessKey.'|'.$channel),
                    'status' => 'queued',
                    'queued_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                    continue;
                }

                throw $exception;
            }

            SendExternalNotification::dispatch($delivery->getKey());
        }
    }
}
