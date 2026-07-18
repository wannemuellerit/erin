<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesUpdateRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                            'sms_enabled' => false,
                            'whatsapp_enabled' => false,
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
        ]);
    }

    public function update(NotificationPreferencesUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, array<string, bool>> $preferences */
        $preferences = $request->validated('preferences');

        DB::transaction(function () use ($user, $preferences): void {
            foreach (NotificationPreference::EVENTS as $event) {
                $preference = $preferences[$event];

                $user->notificationPreferences()->updateOrCreate(
                    ['event' => $event],
                    [
                        'database_enabled' => $preference['database_enabled'],
                        'email_enabled' => $preference['email_enabled'],
                        'push_enabled' => $preference['push_enabled'],
                        // These transports are intentionally unavailable in
                        // the Germany MVP and cannot be enabled via requests.
                        'sms_enabled' => false,
                        'whatsapp_enabled' => false,
                    ],
                );
            }
        });

        return back()->with('success', __('Benachrichtigungseinstellungen wurden gespeichert.'));
    }
}
