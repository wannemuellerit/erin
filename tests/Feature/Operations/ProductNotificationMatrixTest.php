<?php

use App\Enums\UserRole;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('defines a preference category for every product notification event', function () {
    foreach (ProductNotificationDispatcher::EVENTS as $event) {
        $expected = explode('.', $event, 2)[0];
        expect(NotificationPreference::categoryFor($event))
            ->toBe($expected, "{$event} unexpectedly falls back to another category");
    }
});

it('queues each business event exactly once without persisting message content', function () {
    Notification::fake();
    $user = User::factory()->create();
    $dispatcher = app(ProductNotificationDispatcher::class);
    $payload = [
        'title' => 'Safe title',
        'message' => 'Sensitive-free status message',
        'url' => route('dashboard'),
        'application_id' => 42,
    ];

    expect($dispatcher->dispatch($user, 'application.status_changed', 'application:42:status:accepted', $payload))->toBeTrue()
        ->and($dispatcher->dispatch($user, 'application.status_changed', 'application:42:status:accepted', $payload))->toBeFalse();

    Notification::assertSentToTimes($user, ActivityNotification::class, 1);
    expect(NotificationDelivery::query()->count())->toBe(1);
    $this->assertDatabaseMissing('notification_deliveries', [
        'idempotency_key' => 'application:42:status:accepted',
    ]);
    $columns = array_keys(NotificationDelivery::query()->firstOrFail()->getAttributes());
    expect($columns)->not->toContain('payload', 'title', 'message');
});

it('rejects unknown events private fields and external notification links', function (array $case) {
    $user = User::factory()->create();
    $dispatcher = app(ProductNotificationDispatcher::class);

    expect(fn () => $dispatcher->dispatch(
        $user,
        $case['event'],
        'business:1',
        $case['payload'],
    ))->toThrow(InvalidArgumentException::class);

    expect(NotificationDelivery::query()->count())->toBe(0);
})->with([
    'unknown event' => [[
        'event' => 'unknown.event',
        'payload' => ['title' => 'x', 'message' => 'x', 'url' => '/dashboard'],
    ]],
    'private document content' => [[
        'event' => 'document.reviewed',
        'payload' => ['title' => 'x', 'message' => 'x', 'document_name' => 'passport.pdf', 'url' => '/dashboard'],
    ]],
    'external click target' => [[
        'event' => 'support.ticket_replied',
        'payload' => ['title' => 'x', 'message' => 'x', 'url' => 'https://attacker.example/phish'],
    ]],
]);

it('keeps every supported channel disabled when the user opted out', function () {
    $user = User::factory()->create();

    foreach (NotificationPreference::EVENTS as $event) {
        $user->notificationPreferences()->create([
            'event' => $event,
            'database_enabled' => false,
            'email_enabled' => false,
            'push_enabled' => false,
        ]);
    }

    foreach (ProductNotificationDispatcher::EVENTS as $event) {
        $notification = new ActivityNotification([
            'event' => $event,
            'title' => 'Status',
            'message' => 'Status changed',
            'url' => route('dashboard'),
        ]);
        expect($notification->via($user))->toBe([], $event);
    }
});

it('requires and delivers platform announcements in every supported locale', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $translations = [];
    $recipients = [];

    foreach (config('app.supported_locales') as $locale) {
        $translations[$locale] = [
            'title' => "Title {$locale}",
            'message' => "Message {$locale}",
        ];
        $recipients[$locale] = User::factory()->create(['locale' => $locale]);
    }

    $payload = [
        'submission_key' => 'release-announcement-1',
        'audience' => 'all',
        'translations' => $translations,
        'url' => '/dashboard',
    ];

    $invalid = $payload;
    unset($invalid['translations']['hr']);
    $this->actingAs($admin)
        ->post(route('admin.platform-notifications.store'), $invalid)
        ->assertSessionHasErrors('translations.hr');

    $this->actingAs($admin)
        ->post(route('admin.platform-notifications.store'), $payload)
        ->assertRedirect();

    foreach ($recipients as $locale => $recipient) {
        Notification::assertSentTo(
            $recipient,
            ActivityNotification::class,
            fn (ActivityNotification $notification): bool => $notification->toArray($recipient)['title'] === "Title {$locale}"
                && $notification->toArray($recipient)['message'] === "Message {$locale}",
        );
    }
});
