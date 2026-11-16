<?php

namespace App\Jobs;

use App\Contracts\ExternalMessageProvider;
use App\Data\ExternalMessageRequest;
use App\Exceptions\ExternalMessageRateLimited;
use App\Models\ExternalNotificationDelivery;
use App\Models\NotificationPhoneChannel;
use App\Models\User;
use App\Services\Notifications\ExternalNotificationPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendExternalNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800, 7200];

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function handle(ExternalMessageProvider $provider, ExternalNotificationPolicy $policy): void
    {
        $delivery = ExternalNotificationDelivery::query()->findOrFail($this->deliveryId);
        if (in_array($delivery->status, ['sent', 'delivered', 'opted_out'], true)) {
            return;
        }
        $user = User::query()->findOrFail($delivery->user_id);
        $channel = NotificationPhoneChannel::query()
            ->where('user_id', $user->getKey())
            ->where('channel', $delivery->channel)
            ->first();
        if ($channel === null || ! $policy->maySend($user, $channel, $delivery->event)) {
            $delivery->update(['status' => 'suppressed', 'failure_code' => 'consent_or_kill_switch']);

            return;
        }
        if (! $policy->withinMonthlyCostLimit()) {
            $delivery->update(['status' => 'suppressed', 'failure_code' => 'monthly_cost_limit']);

            return;
        }
        $quietHoursDelay = $policy->quietHoursDelay($channel, $user->timezone ?: 'Europe/Berlin');
        if ($quietHoursDelay > 0) {
            $delivery->update(['status' => 'deferred_quiet_hours', 'failure_code' => null]);
            $this->release($quietHoursDelay);

            return;
        }
        if (! $policy->withinUserRateLimit($user->getKey(), $delivery->channel)) {
            $delivery->update(['status' => 'rate_limited', 'failure_code' => 'user_rate_limit']);

            return;
        }

        $delivery->increment('attempts');
        try {
            $result = $provider->send(new ExternalMessageRequest(
                channel: $delivery->channel,
                to: $channel->phone_e164,
                body: $policy->messageFor($delivery->event, $user->locale),
                idempotencyKey: $delivery->idempotency_key,
                locale: in_array($user->locale, config('app.supported_locales'), true)
                    ? $user->locale
                    : 'de',
                templateKey: $delivery->channel === 'whatsapp' ? $delivery->event : null,
            ));
            $optedOut = $result->status === 'opted_out';
            $delivery->update([
                'provider' => $result->provider,
                'provider_message_id' => $result->messageId,
                'status' => $optedOut ? 'opted_out' : 'sent',
                'cost_micros' => $result->costMicros,
                'currency' => $result->currency,
                'sent_at' => $optedOut ? null : now(),
                'failure_code' => $optedOut ? 'provider_opt_out' : null,
            ]);
            if ($optedOut) {
                $channel->update(['revoked_at' => now()]);
            }
        } catch (ExternalMessageRateLimited) {
            $delivery->update(['status' => 'rate_limited', 'failure_code' => 'provider_rate_limit']);
            $this->release(3600);
        } catch (Throwable $exception) {
            $delivery->update(['status' => 'failed', 'failure_code' => class_basename($exception), 'failed_at' => now()]);
            Log::warning('external_notification.send_failed', [
                'delivery_id' => $delivery->getKey(),
                'channel' => $delivery->channel,
                'exception_class' => $exception::class,
            ]);
            throw $exception;
        }
    }
}
