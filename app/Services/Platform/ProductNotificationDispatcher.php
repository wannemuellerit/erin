<?php

namespace App\Services\Platform;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Notifications\ExternalNotificationDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProductNotificationDispatcher
{
    public function __construct(private readonly ExternalNotificationDispatcher $externalNotifications) {}

    /** @var list<string> */
    public const EVENTS = [
        'application.created',
        'application.status_changed',
        'interview.proposed',
        'interview.countered',
        'interview.confirmed',
        'interview.cancelled',
        'interview.reminder',
        'message.received',
        'document.reviewed',
        'visa.step_updated',
        'visa.deadline',
        'referral.status_changed',
        'referral.payout_available',
        'reminder.due',
        'support.ticket_replied',
        'support.customer_replied',
        'billing.payment_warning',
        'boost.available',
        'company.invitation_accepted',
        'system.platform_announcement',
        'system.email_suppressed',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'body', 'content', 'document', 'document_name', 'original_name',
        'email', 'phone', 'address', 'identity', 'candidate_name',
    ];

    /**
     * Dispatches once per user, event and business key. The delivery ledger stores
     * operational metadata only; localized notification content is never persisted there.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(User $user, string $event, string $businessKey, array $payload): bool
    {
        $this->assertPayload($event, $businessKey, $payload);
        $idempotencyKey = hash('sha256', $businessKey);

        try {
            $delivery = NotificationDelivery::query()->create([
                'user_id' => $user->getKey(),
                'event' => $event,
                'idempotency_key' => $idempotencyKey,
                'status' => 'queued',
                'attempts' => 1,
                'queued_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }

        try {
            $user->notify(new ActivityNotification([
                ...$payload,
                'event' => $event,
                'delivery_id' => $delivery->getKey(),
            ]));
            $this->externalNotifications->queue($user, $event, $businessKey, $delivery);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_channel' => 'dispatch',
                'failure_code' => class_basename($exception),
            ]);
            Log::warning('Notification dispatch failed.', [
                'delivery_id' => $delivery->getKey(),
                'event' => $event,
                'exception' => class_basename($exception),
            ]);

            throw $exception;
        }

        return true;
    }

    /** @param  array<string, mixed>  $payload */
    private function assertPayload(string $event, string $businessKey, array $payload): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException("Unknown notification event: {$event}");
        }

        if ($businessKey === '' || mb_strlen($businessKey) > 500) {
            throw new InvalidArgumentException('A bounded notification business key is required.');
        }

        $keys = array_map('strtolower', array_map('strval', Arr::dot($payload) === []
            ? array_keys($payload)
            : array_keys(Arr::dot($payload))));
        foreach ($keys as $key) {
            $leaf = str_contains($key, '.') ? (string) str($key)->afterLast('.') : $key;
            if (in_array($leaf, self::FORBIDDEN_PAYLOAD_KEYS, true)) {
                throw new InvalidArgumentException("Private notification payload field: {$leaf}");
            }
        }

        $url = $payload['url'] ?? null;
        if (! is_string($url) || ! $this->isSameOriginUrl($url)) {
            throw new InvalidArgumentException('Notification links must use the Faden origin.');
        }
    }

    private function isSameOriginUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $target = parse_url($url);
        $origin = parse_url((string) config('app.url'));

        return isset($target['scheme'], $target['host'], $origin['scheme'], $origin['host'])
            && strtolower($target['scheme']) === strtolower($origin['scheme'])
            && strtolower($target['host']) === strtolower($origin['host'])
            && ($target['port'] ?? null) === ($origin['port'] ?? null)
            && ! isset($target['user'], $target['pass']);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
