<?php

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

/**
 * @return array{preferences: array<string, array<string, bool>>}
 */
function erinNotificationPreferencePayload(): array
{
    return [
        'preferences' => collect(NotificationPreference::EVENTS)
            ->mapWithKeys(fn (string $event): array => [
                $event => NotificationPreference::DEFAULTS,
            ])
            ->all(),
    ];
}

it('requires authentication and presents safe defaults for every event category', function () {
    $this->get(route('notification-preferences.edit'))
        ->assertRedirect(route('login'));

    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->get(route('notification-preferences.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('preferences.application.database_enabled', true)
            ->where('preferences.application.email_enabled', true)
            ->where('preferences.application.push_enabled', false)
            ->where('preferences.interview.database_enabled', true)
            ->where('preferences.message.email_enabled', true)
            ->where('preferences.support.push_enabled', false)
            ->where('preferences.system.sms_enabled', false)
            ->where('preferences.system.whatsapp_enabled', false)
            ->has('push_configured')
            ->where('push_subscription_count', 0)
            ->has('push_public_key')
            ->where('push_subscription_store_url', route('push-subscriptions.store'))
            ->where('push_subscription_destroy_url', route('push-subscriptions.destroy')));
});

it('registers updates and removes a browser push subscription for the authenticated user', function () {
    $user = User::factory()->create();
    $payload = [
        'endpoint' => 'https://push.example.test/subscriptions/device-1',
        'keys' => [
            'p256dh' => str_repeat('p', 87),
            'auth' => str_repeat('a', 22),
        ],
        'content_encoding' => 'aes128gcm',
    ];

    $this->post(route('push-subscriptions.store'), $payload)
        ->assertRedirect(route('login'));

    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->post(route('push-subscriptions.store'), $payload)
        ->assertRedirect(route('notification-preferences.edit'))
        ->assertSessionHas('success');

    $subscription = PushSubscription::query()->sole();

    expect($user->ownsPushSubscription($subscription))->toBeTrue()
        ->and($subscription->endpoint)->toBe($payload['endpoint'])
        ->and($subscription->public_key)->toBe($payload['keys']['p256dh'])
        ->and($subscription->auth_token)->toBe($payload['keys']['auth'])
        ->and($subscription->content_encoding?->value)->toBe('aes128gcm');

    $payload['keys']['auth'] = str_repeat('b', 22);
    $payload['content_encoding'] = 'aesgcm';

    $this->actingAs($user)
        ->post(route('push-subscriptions.store'), $payload)
        ->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and($subscription->fresh()?->auth_token)->toBe(str_repeat('b', 22))
        ->and($subscription->fresh()?->content_encoding?->value)->toBe('aesgcm');

    $this->actingAs($user)
        ->delete(route('push-subscriptions.destroy'), [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PushSubscription::query()->count())->toBe(0);
});

it('does not allow users to take over or remove another users push endpoint', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $endpoint = 'https://push.example.test/subscriptions/private-device';
    $owner->updatePushSubscription(
        $endpoint,
        str_repeat('p', 87),
        str_repeat('a', 22),
        'aes128gcm',
    );

    $this->actingAs($attacker)
        ->post(route('push-subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => str_repeat('x', 87),
                'auth' => str_repeat('y', 22),
            ],
            'content_encoding' => 'aes128gcm',
        ])
        ->assertConflict();

    $this->actingAs($attacker)
        ->delete(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertRedirect();

    $subscription = PushSubscription::findByEndpoint($endpoint);

    expect($subscription)->not->toBeNull()
        ->and($subscription !== null && $owner->ownsPushSubscription($subscription))->toBeTrue()
        ->and($subscription?->public_key)->toBe(str_repeat('p', 87));
});

it('validates browser push subscription input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->post(route('push-subscriptions.store'), [
            'endpoint' => 'javascript:alert(1)',
            'keys' => ['p256dh' => '', 'unexpected' => 'value'],
            'content_encoding' => 'unsupported',
        ])
        ->assertRedirect(route('notification-preferences.edit'))
        ->assertSessionHasErrors([
            'endpoint',
            'keys',
            'keys.p256dh',
            'keys.auth',
            'content_encoding',
        ]);

    expect(PushSubscription::query()->count())->toBe(0);
});

it('updates only the authenticated users preferences', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherPreference = $other->notificationPreferences()->create([
        'event' => 'application',
        'database_enabled' => false,
        'email_enabled' => false,
        'push_enabled' => false,
    ]);
    $payload = erinNotificationPreferencePayload();
    $payload['preferences']['application'] = [
        'database_enabled' => false,
        'email_enabled' => true,
        'push_enabled' => true,
        'sms_enabled' => false,
        'whatsapp_enabled' => false,
    ];
    $payload['preferences']['message'] = [
        'database_enabled' => true,
        'email_enabled' => false,
        'push_enabled' => true,
        'sms_enabled' => false,
        'whatsapp_enabled' => false,
    ];

    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->patch(route('notification-preferences.update'), $payload)
        ->assertRedirect(route('notification-preferences.edit'))
        ->assertSessionHas('success');

    expect($user->notificationPreferences()->count())->toBe(5)
        ->and($user->notificationPreferences()->where('event', 'application')->firstOrFail())
        ->database_enabled->toBeFalse()
        ->email_enabled->toBeTrue()
        ->push_enabled->toBeTrue()
        ->sms_enabled->toBeFalse()
        ->whatsapp_enabled->toBeFalse()
        ->and($user->notificationPreferences()->where('event', 'message')->firstOrFail())
        ->database_enabled->toBeTrue()
        ->email_enabled->toBeFalse()
        ->push_enabled->toBeTrue()
        ->and($otherPreference->fresh())
        ->database_enabled->toBeFalse()
        ->email_enabled->toBeFalse()
        ->push_enabled->toBeFalse();
});

it('rejects incomplete unknown and unavailable transport preferences', function () {
    $user = User::factory()->create();

    $missingEvent = erinNotificationPreferencePayload();
    unset($missingEvent['preferences']['support']);
    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->patch(route('notification-preferences.update'), $missingEvent)
        ->assertSessionHasErrors('preferences.support');

    $unknownEvent = erinNotificationPreferencePayload();
    $unknownEvent['preferences']['marketing'] = NotificationPreference::DEFAULTS;
    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->patch(route('notification-preferences.update'), $unknownEvent)
        ->assertSessionHasErrors('preferences');

    $smsEnabled = erinNotificationPreferencePayload();
    $smsEnabled['preferences']['application']['sms_enabled'] = true;
    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->patch(route('notification-preferences.update'), $smsEnabled)
        ->assertSessionHasErrors('preferences.application.sms_enabled');

    $whatsAppEnabled = erinNotificationPreferencePayload();
    $whatsAppEnabled['preferences']['message']['whatsapp_enabled'] = true;
    $this->actingAs($user)
        ->from(route('notification-preferences.edit'))
        ->patch(route('notification-preferences.update'), $whatsAppEnabled)
        ->assertSessionHasErrors('preferences.message.whatsapp_enabled');

    expect($user->notificationPreferences()->count())->toBe(0);
});

it('selects notification channels by exact event category and safe defaults', function () {
    $user = User::factory()->create();
    $application = new ActivityNotification([
        'event' => 'application.status_changed',
        'title' => 'Status',
        'message' => 'Changed',
    ]);

    expect($application->via($user))->toBe(['database', 'broadcast', 'mail']);

    $user->notificationPreferences()->create([
        'event' => 'application',
        'database_enabled' => false,
        'email_enabled' => false,
        'push_enabled' => true,
    ]);

    expect($application->via($user))->toBe([WebPushChannel::class]);

    $user->notificationPreferences()->create([
        'event' => 'application.status_changed',
        'database_enabled' => true,
        'email_enabled' => false,
        'push_enabled' => true,
    ]);

    expect($application->via($user))->toBe([
        'database',
        'broadcast',
        WebPushChannel::class,
    ]);

    $user->notificationPreferences()->create([
        'event' => 'system',
        'database_enabled' => false,
        'email_enabled' => true,
        'push_enabled' => false,
    ]);
    $unknownSystemEvent = new ActivityNotification([
        'event' => 'billing.payment_failed',
        'title' => 'Payment',
        'message' => 'Please review.',
    ]);

    expect($unknownSystemEvent->via($user))->toBe(['mail'])
        ->and($unknownSystemEvent->via($user))->not->toContain('broadcast');
});

it('persists in app notifications only while the database channel is enabled', function () {
    $user = User::factory()->create();
    $preference = $user->notificationPreferences()->create([
        'event' => 'message',
        'database_enabled' => true,
        'email_enabled' => false,
        'push_enabled' => false,
    ]);
    $notification = new ActivityNotification([
        'event' => 'message.received',
        'title' => 'Neue Nachricht',
        'message' => 'Eine neue Nachricht ist eingegangen.',
    ]);

    $user->notifyNow($notification);

    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => User::class,
        'notifiable_id' => $user->getKey(),
    ]);

    $preference->update(['database_enabled' => false]);
    $user->notifyNow($notification);

    $this->assertDatabaseCount('notifications', 1);
});

it('builds localized database mail broadcast and web push payloads', function () {
    $notification = new ActivityNotification([
        'event' => 'message.received',
        'title' => 'Fallback title',
        'message' => 'Fallback message',
        'url' => 'https://erin.example/messages/42',
        'translations' => [
            'de' => [
                'title' => 'Neue Nachricht',
                'message' => 'Du hast eine neue Nachricht erhalten.',
            ],
            'en' => [
                'title' => 'New message',
                'message' => 'You received a new message.',
            ],
        ],
    ]);
    $englishUser = User::factory()->create([
        'name' => 'Ada',
        'locale' => 'en',
    ]);
    $germanUser = User::factory()->create([
        'name' => 'Emil',
        'locale' => 'de',
    ]);

    $englishMail = $notification->toMail($englishUser);
    $englishPush = $notification->toWebPush($englishUser)->toArray();
    $englishBroadcast = $notification->toBroadcast($englishUser)->data;
    $germanMail = $notification->toMail($germanUser);
    $germanPush = $notification->toWebPush($germanUser)->toArray();

    expect($notification->toArray($englishUser))
        ->toMatchArray([
            'title' => 'New message',
            'message' => 'You received a new message.',
        ])
        ->not->toHaveKey('translations')
        ->and($englishBroadcast)
        ->toMatchArray([
            'title' => 'New message',
            'message' => 'You received a new message.',
        ])
        ->and($englishMail->subject)->toBe('New message')
        ->and($englishMail->greeting)->toBe('Hello Ada,')
        ->and($englishMail->actionText)->toBe('View in Erin')
        ->and($englishMail->actionUrl)->toBe('https://erin.example/messages/42')
        ->and($englishPush)
        ->toMatchArray([
            'title' => 'New message',
            'body' => 'You received a new message.',
            'lang' => 'en',
            'tag' => 'erin-message',
            'data' => [
                'event' => 'message.received',
                'url' => 'https://erin.example/messages/42',
            ],
        ])
        ->and($germanMail->subject)->toBe('Neue Nachricht')
        ->and($germanMail->greeting)->toBe('Hallo Emil,')
        ->and($germanMail->actionText)->toBe('In Erin ansehen')
        ->and($germanPush)
        ->toMatchArray([
            'title' => 'Neue Nachricht',
            'body' => 'Du hast eine neue Nachricht erhalten.',
            'lang' => 'de',
            'tag' => 'erin-message',
        ]);
});
