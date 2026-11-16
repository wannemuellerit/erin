<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesUpdateRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $stored = $user->notificationPreferences()
            ->whereIn('event', NotificationPreference::EVENTS)
            ->get()
            ->keyBy('event');

        $preferences = collect(NotificationPreference::EVENTS)
            ->mapWithKeys(function (string $event) use ($stored): array {
                $preference = $stored->get($event);

                return [
                    $event => $preference === null
                        ? NotificationPreference::DEFAULTS
                        : [
                            'database_enabled' => $preference->database_enabled,
                            'email_enabled' => $preference->email_enabled,
                            'push_enabled' => $preference->push_enabled,
                            'sms_enabled' => $preference->sms_enabled,
                            'whatsapp_enabled' => $preference->whatsapp_enabled,
                        ],
                ];
            });

        return Inertia::render('settings/Notifications', [
            'preferences' => $preferences,
            'push_configured' => filled(config('webpush.vapid.public_key'))
                && filled(config('webpush.vapid.private_key')),
            'push_public_key' => (string) config('webpush.vapid.public_key', ''),
            'push_subscription_count' => $user->pushSubscriptions()->count(),
            'push_subscription_store_url' => route('push-subscriptions.store'),
            'push_subscription_destroy_url' => route('push-subscriptions.destroy'),
            'push_subscription_test_url' => route('push-subscriptions.test'),
            'phone_channels_configured' => (bool) config('services.twilio.enabled', false),
            'phone_channels' => $user->notificationPhoneChannels()->get()->map(fn ($channel): array => [
                'id' => $channel->getKey(),
                'channel' => $channel->channel,
                'masked_phone' => '••••'.substr($channel->phone_e164, -3),
                'country_code' => $channel->country_code,
                'verified' => $channel->verified_at !== null,
                'consented' => $channel->consented_at !== null && $channel->revoked_at === null,
                'quiet_hours_start' => $channel->quiet_hours_start,
                'quiet_hours_end' => $channel->quiet_hours_end,
            ])->values(),
            'phone_channel_store_url' => route('notification-phone-channels.store'),
            'phone_allowed_countries' => config('services.twilio.allowed_countries', ['DE']),
        ]);
    }

    public function update(NotificationPreferencesUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, array<string, bool>> $preferences */
        $preferences = $request->validated('preferences');
        $allowedCategories = ['interview', 'document', 'visa', 'reminder', 'support'];
        $channels = $user->notificationPhoneChannels()->get()->keyBy('channel');
        foreach ($preferences as $event => $preference) {
            foreach (['sms', 'whatsapp'] as $channelName) {
                if (! $preference["{$channelName}_enabled"]) {
                    continue;
                }
                $channel = $channels->get($channelName);
                if (! in_array($event, $allowedCategories, true) || $channel === null || ! $channel->canReceive()) {
                    throw ValidationException::withMessages([
                        "preferences.{$event}.{$channelName}_enabled" => __('Dieser Kanal muss zuerst verifiziert und ausdrücklich freigegeben werden.'),
                    ]);
                }
            }
        }

        DB::transaction(function () use ($user, $preferences, $allowedCategories): void {
            foreach (NotificationPreference::EVENTS as $event) {
                $preference = $preferences[$event];

                $user->notificationPreferences()->updateOrCreate(
                    ['event' => $event],
                    [
                        'database_enabled' => $preference['database_enabled'],
                        'email_enabled' => $preference['email_enabled'],
                        'push_enabled' => $preference['push_enabled'],
                        'sms_enabled' => in_array($event, $allowedCategories, true)
                            && $preference['sms_enabled'],
                        'whatsapp_enabled' => in_array($event, $allowedCategories, true)
                            && $preference['whatsapp_enabled'],
                    ],
                );
            }
        });

        return back()->with('success', __('Benachrichtigungseinstellungen wurden gespeichert.'));
    }
}
