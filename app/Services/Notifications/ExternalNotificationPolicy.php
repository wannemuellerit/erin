<?php

namespace App\Services\Notifications;

use App\Models\ExternalNotificationDelivery;
use App\Models\FeatureFlag;
use App\Models\NotificationPhoneChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ExternalNotificationPolicy
{
    /** @var list<string> */
    public const ALLOWED_EVENTS = [
        'interview.proposed', 'interview.countered', 'interview.confirmed', 'interview.cancelled', 'interview.reminder',
        'document.reviewed', 'visa.step_updated', 'visa.deadline', 'reminder.due', 'support.ticket_replied',
    ];

    public function globallyEnabled(): bool
    {
        if (! (bool) config('services.twilio.enabled', false)) {
            return false;
        }

        try {
            $flag = FeatureFlag::query()->where('key', 'external_notifications')->first();

            return $flag === null || ($flag->enabled && $flag->rollout_percentage > 0);
        } catch (Throwable) {
            return false;
        }
    }

    public function maySend(User $user, NotificationPhoneChannel $channel, string $event): bool
    {
        if (! $this->globallyEnabled() || ! in_array($event, self::ALLOWED_EVENTS, true)) {
            return false;
        }
        if (! $channel->canReceive() || $channel->user_id !== $user->getKey()) {
            return false;
        }
        $allowedCountries = config('services.twilio.allowed_countries', ['DE']);
        if (! is_array($allowedCountries) || ! in_array($channel->country_code, $allowedCountries, true)) {
            return false;
        }

        return $this->preferenceEnabled($user, $event, $channel->channel);
    }

    public function preferenceEnabled(User $user, string $event, string $channel): bool
    {
        $category = NotificationPreference::categoryFor($event);
        $preference = $user->notificationPreferences()
            ->whereIn('event', [$event, $category, 'default'])
            ->orderByRaw('field(event, ?, ?, ?)', [$event, $category, 'default'])
            ->first();
        $column = $channel === 'whatsapp' ? 'whatsapp_enabled' : 'sms_enabled';

        return $preference !== null && (bool) $preference->getAttribute($column);
    }

    public function withinUserRateLimit(int $userId, string $channel): bool
    {
        $key = sprintf('external-notification:%d:%s:%s', $userId, $channel, now()->format('YmdH'));
        $count = (int) Cache::increment($key);
        Cache::put($key, $count, now()->addHours(2));

        return $count <= (int) config('services.twilio.user_hourly_limit', 5);
    }

    public function withinMonthlyCostLimit(): bool
    {
        $cost = (int) ExternalNotificationDelivery::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');

        return $cost < (int) config('services.twilio.monthly_cost_limit_micros', 100000000);
    }

    public function quietHoursDelay(NotificationPhoneChannel $channel, string $timezone): int
    {
        if (! is_string($channel->quiet_hours_start) || ! is_string($channel->quiet_hours_end)) {
            return 0;
        }
        $now = Carbon::now($timezone);
        [$startHour, $startMinute] = array_map('intval', explode(':', $channel->quiet_hours_start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $channel->quiet_hours_end));
        $start = $now->copy()->setTime($startHour, $startMinute);
        $end = $now->copy()->setTime($endHour, $endMinute);
        if ($start->equalTo($end)) {
            return 0;
        }
        if ($start->lessThan($end)) {
            return $now->betweenIncluded($start, $end) && $now->lessThan($end)
                ? (int) max(60, $now->diffInSeconds($end))
                : 0;
        }
        if ($now->greaterThanOrEqualTo($start)) {
            return (int) max(60, $now->diffInSeconds($end->addDay()));
        }

        return $now->lessThan($end) ? (int) max(60, $now->diffInSeconds($end)) : 0;
    }

    public function messageFor(string $event, string $locale): string
    {
        $kind = str_starts_with($event, 'interview.') ? 'interview'
            : (str_starts_with($event, 'document.') ? 'document'
                : (str_starts_with($event, 'visa.') ? 'visa'
                    : (str_starts_with($event, 'support.') ? 'support' : 'reminder')));
        $messages = [
            'interview' => 'In Faden gibt es eine zeitkritische Aktualisierung zu einem Interview.',
            'document' => 'In Faden gibt es eine Aktualisierung zu einer Dokumentfrist.',
            'visa' => 'In Faden gibt es eine zeitkritische Aktualisierung zu deinem Onboarding.',
            'support' => 'Das Faden-Support-Team hat auf dein Ticket geantwortet.',
            'reminder' => 'In Faden ist eine persönliche Erinnerung fällig.',
        ];

        return __($messages[$kind], [], $locale);
    }
}
