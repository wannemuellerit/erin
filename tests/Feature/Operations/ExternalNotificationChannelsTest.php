<?php

use App\Contracts\ExternalMessageProvider;
use App\Enums\UserRole;
use App\Exceptions\ExternalMessageRateLimited;
use App\Jobs\SendExternalNotification;
use App\Models\ExternalNotificationDelivery;
use App\Models\ExternalNotificationWebhookReceipt;
use App\Models\FeatureFlag;
use App\Models\NotificationPhoneChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\ExternalNotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErinExternalMessageProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.twilio.enabled', true);
    config()->set('services.twilio.allowed_countries', ['DE']);
    config()->set('services.twilio.webhook_secret', str_repeat('t', 32));
    config()->set('services.twilio.user_hourly_limit', 5);
    config()->set('services.twilio.monthly_cost_limit_micros', 1000000);
    $fake = new ErinExternalMessageProvider;
    app()->instance(ExternalMessageProvider::class, $fake);
});

function erinPhoneChannel(User $user, array $attributes = []): NotificationPhoneChannel
{
    return NotificationPhoneChannel::query()->create([
        'user_id' => $user->getKey(),
        'channel' => 'sms',
        'phone_e164' => '+491701234567',
        'phone_hash' => hash('sha256', '+491701234567'),
        'country_code' => 'DE',
        'verified_at' => now(),
        'consented_at' => now(),
        'consent_version' => 'phone-notifications-v1',
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,
        ...$attributes,
    ]);
}

function erinExternalDelivery(User $user, array $attributes = []): ExternalNotificationDelivery
{
    return ExternalNotificationDelivery::query()->create([
        'user_id' => $user->getKey(),
        'channel' => 'sms',
        'event' => 'interview.confirmed',
        'idempotency_key' => hash('sha256', 'interview-1|sms'),
        'status' => 'queued',
        'queued_at' => now(),
        ...$attributes,
    ]);
}

function erinEnablePhonePreference(User $user, string $channel = 'sms'): void
{
    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'event' => 'interview',
        'database_enabled' => true,
        'email_enabled' => false,
        'push_enabled' => false,
        'sms_enabled' => $channel === 'sms',
        'whatsapp_enabled' => $channel === 'whatsapp',
    ]);
}

it('sends nothing without both verification and explicit channel opt in', function (array $channelAttributes, bool $preference) {
    $user = User::factory()->create();
    erinPhoneChannel($user, $channelAttributes);
    if ($preference) {
        erinEnablePhonePreference($user);
    }
    $delivery = erinExternalDelivery($user);

    (new SendExternalNotification($delivery->getKey()))->handle(
        app(ExternalMessageProvider::class),
        app(ExternalNotificationPolicy::class),
    );

    expect(app(ExternalMessageProvider::class)->requests)->toBeEmpty();
    expect($delivery->fresh()->status)->toBe('suppressed');
})->with([
    'unverified' => [['verified_at' => null], true],
    'no consent' => [['consented_at' => null], true],
    'revoked' => [['revoked_at' => now()], true],
    'no preference' => [[], false],
]);

it('rechecks revocation immediately before the queued send', function () {
    $user = User::factory()->create();
    $channel = erinPhoneChannel($user);
    erinEnablePhonePreference($user);
    $delivery = erinExternalDelivery($user);
    $channel->update(['revoked_at' => now(), 'consented_at' => null]);

    (new SendExternalNotification($delivery->getKey()))->handle(app(ExternalMessageProvider::class), app(ExternalNotificationPolicy::class));

    expect(app(ExternalMessageProvider::class)->requests)->toBeEmpty();
    expect($delivery->fresh()->failure_code)->toBe('consent_or_kill_switch');
});

it('sends a localized data-minimized message and records provider cost', function () {
    $user = User::factory()->create(['locale' => 'en']);
    erinPhoneChannel($user);
    erinEnablePhonePreference($user);
    $delivery = erinExternalDelivery($user);

    (new SendExternalNotification($delivery->getKey()))->handle(app(ExternalMessageProvider::class), app(ExternalNotificationPolicy::class));

    $request = app(ExternalMessageProvider::class)->requests[0];
    expect($request->body)
        ->toBe('There is a time-sensitive interview update in Faden.')
        ->not->toContain($user->name, $user->email);
    expect($delivery->fresh())
        ->status->toBe('sent')
        ->cost_micros->toBe(75000)
        ->provider_message_id->toBe('fake-message-1');
});

it('enforces the global admin kill switch and user rate limit', function () {
    $user = User::factory()->create();
    erinPhoneChannel($user);
    erinEnablePhonePreference($user);
    FeatureFlag::query()->create([
        'key' => 'external_notifications',
        'name' => 'External notifications',
        'enabled' => false,
        'rollout_percentage' => 0,
    ]);
    $killed = erinExternalDelivery($user);
    (new SendExternalNotification($killed->getKey()))->handle(app(ExternalMessageProvider::class), app(ExternalNotificationPolicy::class));
    expect($killed->fresh()->status)->toBe('suppressed');

    FeatureFlag::query()->where('key', 'external_notifications')->update(['enabled' => true, 'rollout_percentage' => 100]);
    config()->set('services.twilio.user_hourly_limit', 0);
    Cache::flush();
    $limited = erinExternalDelivery($user, ['idempotency_key' => hash('sha256', 'interview-2|sms')]);
    (new SendExternalNotification($limited->getKey()))->handle(app(ExternalMessageProvider::class), app(ExternalNotificationPolicy::class));
    expect($limited->fresh())->status->toBe('rate_limited')->failure_code->toBe('user_rate_limit');
    expect(app(ExternalMessageProvider::class)->requests)->toBeEmpty();
});

it('handles provider opt out, provider failure and provider rate limit without leaking content', function () {
    $user = User::factory()->create();
    $channel = erinPhoneChannel($user);
    erinEnablePhonePreference($user);
    $fake = app(ExternalMessageProvider::class);
    $fake->status = 'opted_out';
    $optedOut = erinExternalDelivery($user);
    (new SendExternalNotification($optedOut->getKey()))->handle($fake, app(ExternalNotificationPolicy::class));
    expect($optedOut->fresh()->status)->toBe('opted_out');
    expect($channel->fresh()->revoked_at)->not->toBeNull();

    $channel->update(['revoked_at' => null, 'consented_at' => now()]);
    $fake->status = 'queued';
    $fake->exception = new ExternalMessageRateLimited('provider details');
    $limited = erinExternalDelivery($user, ['idempotency_key' => hash('sha256', 'limited')]);
    (new SendExternalNotification($limited->getKey()))->handle($fake, app(ExternalNotificationPolicy::class));
    expect($limited->fresh())->status->toBe('rate_limited')->failure_code->toBe('provider_rate_limit');

    $fake->exception = new RuntimeException('secret provider response');
    $failed = erinExternalDelivery($user, ['idempotency_key' => hash('sha256', 'failed')]);
    expect(fn () => (new SendExternalNotification($failed->getKey()))->handle($fake, app(ExternalNotificationPolicy::class)))
        ->toThrow(RuntimeException::class);
    expect($failed->fresh())->status->toBe('failed')->failure_code->toBe('RuntimeException');
});

it('processes signed delivery and stop webhooks idempotently', function () {
    $user = User::factory()->create();
    $channel = erinPhoneChannel($user);
    erinEnablePhonePreference($user);
    $delivery = erinExternalDelivery($user, [
        'provider' => 'twilio',
        'provider_message_id' => 'SM-123',
        'status' => 'sent',
    ]);
    $payload = json_encode([
        'MessageSid' => 'SM-123',
        'MessageStatus' => 'delivered',
        'Timestamp' => '1',
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $payload, str_repeat('t', 32));

    $this->call('POST', route('integrations.external-notifications.webhook', 'twilio'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ERIN_SIGNATURE' => $signature,
    ], $payload)->assertOk()->assertJsonPath('received', true);
    $this->call('POST', route('integrations.external-notifications.webhook', 'twilio'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ERIN_SIGNATURE' => $signature,
    ], $payload)->assertOk()->assertJsonPath('duplicate', true);
    expect($delivery->fresh()->status)->toBe('delivered');
    expect(ExternalNotificationWebhookReceipt::query()->count())->toBe(1);

    $stopPayload = json_encode([
        'MessageSid' => 'SM-inbound',
        'MessageStatus' => 'received',
        'Timestamp' => '2',
        'Body' => 'STOP',
        'From' => '+491701234567',
    ], JSON_THROW_ON_ERROR);
    $this->call('POST', route('integrations.external-notifications.webhook', 'twilio'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ERIN_SIGNATURE' => hash_hmac('sha256', $stopPayload, str_repeat('t', 32)),
    ], $stopPayload)->assertOk();
    expect($channel->fresh())->revoked_at->not->toBeNull()->consented_at->toBeNull();
    expect(NotificationPreference::query()->where('user_id', $user->getKey())->sole()->sms_enabled)->toBeFalse();
});

it('rejects oversized, incomplete and conflicting external notification callbacks', function () {
    $route = route('integrations.external-notifications.webhook', 'twilio');
    $send = function (array $data, string $eventId = 'twilio-event-1') use ($route) {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->call('POST', $route, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_I_TWILIO_IDEMPOTENCY_TOKEN' => $eventId,
            'HTTP_X_ERIN_SIGNATURE' => hash_hmac('sha256', $body, str_repeat('t', 32)),
        ], $body);
    };
    $payload = ['MessageSid' => 'SM-conflict', 'MessageStatus' => 'delivered'];

    config()->set('services.twilio.webhook_max_bytes', 8);
    $send($payload)->assertStatus(413);

    config()->set('services.twilio.webhook_max_bytes', 4096);
    $send(['MessageStatus' => 'delivered'], 'twilio-incomplete')->assertUnprocessable();
    $send($payload)->assertOk();
    $send([...$payload, 'MessageStatus' => 'failed'])->assertConflict();

    expect(ExternalNotificationWebhookReceipt::query()->count())->toBe(1);
});

it('stores phone numbers encrypted and never exposes them in settings props', function () {
    $user = User::factory()->create(['role' => UserRole::Candidate]);
    $channel = erinPhoneChannel($user);
    expect(DB::table('notification_phone_channels')->where('id', $channel->getKey())->value('phone_e164'))
        ->not->toContain('+491701234567');

    $response = $this->actingAs($user)->get(route('notification-preferences.edit'))->assertOk();
    expect($response->getContent())->not->toContain('+491701234567');
});
