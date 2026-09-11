<?php

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function erinMailWebhook(string $provider, array $payload, string $secret = 'mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ERIN_MAIL_SIGNATURE' => hash_hmac('sha256', $body, $secret),
    ];

    return test()->call(
        'POST',
        route('integrations.mail.webhook', ['provider' => $provider]),
        [],
        [],
        [],
        $server,
        $body,
    );
}

it('suppresses a hard bounce exactly once and leaves an in-app warning', function () {
    Notification::fake();
    config()->set('services.mail_delivery.webhook_secret', str_repeat('m', 32));
    $user = User::factory()->create(['email' => 'delivery@example.test']);
    app(ProductNotificationDispatcher::class)->dispatch(
        $user,
        'message.received',
        'message:delivery-test',
        [
            'title' => 'Neue Nachricht',
            'message' => 'Eine neue Nachricht ist verfügbar.',
            'url' => route('messages.index'),
        ],
    );
    $delivery = NotificationDelivery::query()->firstOrFail();
    $payload = [
        'RecordType' => 'HardBounce',
        'MessageID' => 'provider-message-1',
        'Email' => 'DELIVERY@example.test',
        'Metadata' => ['notification_delivery_id' => (string) $delivery->getKey()],
    ];

    erinMailWebhook('postmark', $payload)->assertAccepted();
    erinMailWebhook('postmark', $payload)->assertOk()->assertJsonPath('duplicate', true);

    expect(DB::table('email_delivery_events')->count())->toBe(1)
        ->and(DB::table('email_suppressions')->count())->toBe(1)
        ->and(DB::table('email_suppressions')->first()->email_hash)->not->toContain('delivery@example.test')
        ->and($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->failure_code)->toBe('hard_bounce')
        ->and($user->routeNotificationForMail())->toBeNull();
    Notification::assertSentTo(
        $user,
        ActivityNotification::class,
        fn ($notification, array $channels): bool => in_array('database', $channels, true),
    );
});

it('records a soft bounce without suppressing future mail', function () {
    Notification::fake();
    config()->set('services.mail_delivery.webhook_secret', str_repeat('m', 32));
    $user = User::factory()->create(['email' => 'temporary@example.test']);

    erinMailWebhook('resend', [
        'type' => 'email.bounced',
        'data' => [
            'email_id' => 'resend-event-1',
            'to' => ['temporary@example.test'],
            'metadata' => [],
        ],
    ])->assertAccepted();

    expect(DB::table('email_delivery_events')->value('event_type'))->toBe('soft_bounce')
        ->and(DB::table('email_suppressions')->count())->toBe(0)
        ->and($user->routeNotificationForMail())->toBe('temporary@example.test');
});

it('rejects unsigned mail provider events and tags queued messages without content metadata', function () {
    config()->set('services.mail_delivery.webhook_secret', str_repeat('m', 32));
    $this->postJson(route('integrations.mail.webhook', ['provider' => 'postmark']), [
        'RecordType' => 'HardBounce',
        'MessageID' => 'forged',
        'Email' => 'target@example.test',
    ])->assertUnauthorized();

    $user = User::factory()->create();
    $mail = (new ActivityNotification([
        'event' => 'interview.confirmed',
        'delivery_id' => 42,
        'title' => 'Interview',
        'message' => 'Termin bestätigt',
        'url' => route('interviews.index'),
    ]))->toMail($user);

    expect($mail->tags)->toContain('erin-interview')
        ->and($mail->metadata)->toBe([
            'notification_delivery_id' => '42',
            'event' => 'interview.confirmed',
        ])
        ->and(json_encode($mail->metadata))->not->toContain('Termin bestätigt');
});

it('rejects weak, oversized, ambiguous and conflicting mail provider events', function () {
    $payload = [
        'RecordType' => 'Delivery',
        'MessageID' => 'provider-event-1',
        'Email' => 'delivery@example.test',
    ];

    config()->set('services.mail_delivery.webhook_secret', 'weak');
    erinMailWebhook('postmark', $payload, 'weak')->assertServiceUnavailable();

    config()->set('services.mail_delivery.webhook_secret', str_repeat('m', 32));
    config()->set('services.mail_delivery.webhook_max_bytes', 8);
    erinMailWebhook('postmark', $payload)->assertStatus(413);

    config()->set('services.mail_delivery.webhook_max_bytes', 4096);
    erinMailWebhook('postmark', [...$payload, 'MessageID' => str_repeat('e', 191)])
        ->assertUnprocessable();
    erinMailWebhook('postmark', $payload)->assertAccepted();
    erinMailWebhook('postmark', [...$payload, 'RecordType' => 'HardBounce'])
        ->assertConflict();

    expect(DB::table('email_delivery_events')->count())->toBe(1)
        ->and(DB::table('email_delivery_events')->value('event_type'))->toBe('delivered');
});
