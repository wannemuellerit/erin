<?php

use App\Contracts\TicketingProvider;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Jobs\ImportZammadAttachment;
use App\Jobs\ProcessSupportWebhookOutbox;
use App\Jobs\ProcessSupportZammadWebhookInbox;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\IntegrationReceipt;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportWebhookOutbox;
use App\Models\SupportZammadWebhookDelivery;
use App\Models\SupportZammadWebhookInbox;
use App\Models\User;
use App\Services\Ticketing\SupportOutboundReconciliationDispatcher;
use App\Services\Ticketing\SupportWebhookOutboxEffects;
use App\Services\Ticketing\SupportWebhookOutboxRecorder;
use App\Services\Ticketing\SupportZammadWebhookInboxDispatcher;
use App\Services\Ticketing\SupportZammadWebhookInboxRecorder;
use App\Services\Ticketing\SupportZammadWebhookInboxRetention;
use App\Services\Ticketing\ZammadWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'testing');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set('services.zammad.group', 'Erin Support');
    config()->set(
        'services.zammad.webhook_secret',
        'zammad-webhook-secret-with-32-chars',
    );
    config()->set(
        'services.zammad.message_marker_secret',
        'zammad-message-marker-secret-current',
    );
    config()->set('services.zammad.previous_message_marker_secrets', []);
    config()->set('services.zammad.reconcile_initial_delay_seconds', 1);
    config()->set('services.zammad.reconcile_interval_seconds', 1);
    config()->set('services.zammad.reconcile_required_misses', 2);
    Storage::fake('private');
});

/**
 * @param  array<string, mixed>  $payload
 */
function erinRuntimeZammadWebhook(
    TestCase $test,
    array $payload,
    string $deliveryId,
): TestResponse {
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = app(ZammadWebhookSignature::class)->create(
        $json,
        (string) config('services.zammad.webhook_secret'),
    );

    return $test->call(
        'POST',
        route('integrations.zammad.webhook'),
        [],
        [],
        [],
        [
            'HTTP_X_HUB_SIGNATURE' => $signature,
            'HTTP_X_ZAMMAD_DELIVERY' => $deliveryId,
            'CONTENT_TYPE' => 'application/json',
        ],
        $json,
    );
}

it('persists an early signed webhook and replays it exactly once after ticket mapping', function () {
    Bus::fake([
        ImportZammadAttachment::class,
        ProcessSupportWebhookOutbox::class,
        ProcessSupportZammadWebhookInbox::class,
    ]);
    Notification::fake();
    $payload = [
        'ticket' => [
            'id' => 980,
            'title' => 'Frühe Zammad-Antwort',
            'state' => 'open',
            'updated_at' => now()->utc()->subMinute()->toIso8601String(),
            'article' => [
                'id' => 9802,
                'ticket_id' => 980,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Diese Antwort kam vor der lokalen Zuordnung.',
                'updated_at' => now()->utc()->subMinute()->toIso8601String(),
            ],
        ],
    ];

    erinRuntimeZammadWebhook($this, $payload, 'delivery-before-mapping')
        ->assertAccepted()
        ->assertJson(['accepted' => true, 'matched' => false]);
    erinRuntimeZammadWebhook($this, $payload, 'delivery-same-body-retry')
        ->assertAccepted();

    $inbox = SupportZammadWebhookInbox::query()->sole();
    expect($inbox)
        ->delivery_id->toBe('delivery-before-mapping')
        ->external_ticket_id->toBe('980')
        ->attempts->toBe(0)
        ->processed_at->toBeNull()
        ->terminal_at->toBeNull()
        ->and(IntegrationReceipt::query()->count())->toBe(0)
        ->and(SupportZammadWebhookDelivery::query()->count())->toBe(2);

    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ZAMMAD-EARLY-01',
        'subject' => 'Lokales Ticket',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'pending',
    ]);
    $openingMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Lokale Eröffnungsnachricht.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->once()->andReturnTrue();
    $provider->shouldReceive('createTicket')->once()->andReturn([
        'external_id' => '980',
        'external_article_id' => '9801',
    ]);

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);

    expect($ticket->fresh()->external_id)->toBe('980')
        ->and($openingMessage->fresh()->external_article_id)->toBe('9801');
    Bus::assertDispatched(
        ProcessSupportZammadWebhookInbox::class,
        fn (ProcessSupportZammadWebhookInbox $job): bool => $job->inboxId
            === $inbox->getKey(),
    );

    (new ProcessSupportZammadWebhookInbox($inbox->getKey()))
        ->handle(app(ZammadWebhookSignature::class));

    expect($inbox->fresh())
        ->attempts->toBe(1)
        ->processed_at->not->toBeNull()
        ->terminal_at->toBeNull()
        ->raw_payload->toBe('')
        ->and(
            SupportTicketMessage::query()
                ->where('external_article_id', '9802')
                ->sole()
                ->body,
        )->toBe('Diese Antwort kam vor der lokalen Zuordnung.')
        ->and(IntegrationReceipt::query()->count())->toBe(1);

    (new ProcessSupportZammadWebhookInbox($inbox->getKey()))
        ->handle(app(ZammadWebhookSignature::class));

    expect($inbox->fresh()->attempts)->toBe(1)
        ->and(
            SupportTicketMessage::query()
                ->where('external_article_id', '9802')
                ->count(),
        )->toBe(1);

    app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-additional-alias',
        '980',
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
    expect(fn () => app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-same-body-retry',
        '980',
        json_encode([
            'ticket' => ['id' => 980, 'title' => 'Widerspruch'],
        ], JSON_THROW_ON_ERROR),
    ))->toThrow(RuntimeException::class, 'widersprüchlichem Inhalt')
        ->and(SupportZammadWebhookInbox::query()->count())->toBe(1)
        ->and(SupportZammadWebhookDelivery::query()->count())->toBe(2);
});

it('bounds unsigned delivery aliases and records none after terminalization', function () {
    config()->set(
        'services.zammad.unmatched_webhook_retention_hours',
        1,
    );
    $rawPayload = json_encode([
        'ticket' => ['id' => 989, 'title' => 'Alias-Grenztest'],
    ], JSON_THROW_ON_ERROR);

    for (
        $alias = 1;
        $alias <= SupportZammadWebhookInboxRecorder::MAX_DELIVERY_ALIASES_PER_PAYLOAD;
        $alias++
    ) {
        app(SupportZammadWebhookInboxRecorder::class)->record(
            'delivery-alias-'.$alias,
            '989',
            $rawPayload,
        );
    }

    $inbox = SupportZammadWebhookInbox::query()->sole();
    expect(SupportZammadWebhookDelivery::query()->count())
        ->toBe(
            SupportZammadWebhookInboxRecorder::MAX_DELIVERY_ALIASES_PER_PAYLOAD,
        )
        ->and(fn () => app(
            SupportZammadWebhookInboxRecorder::class,
        )->record(
            'delivery-alias-overflow',
            '989',
            $rawPayload,
        ))->toThrow(RuntimeException::class, 'maximale Anzahl');

    $inbox->update([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);
    expect(app(
        SupportZammadWebhookInboxRetention::class,
    )->terminalizeExpired($inbox->getKey()))->toBe(1);

    foreach (range(1, 25) as $replay) {
        app(SupportZammadWebhookInboxRecorder::class)->record(
            'delivery-terminal-replay-'.$replay,
            '989',
            $rawPayload,
        );
    }

    expect($inbox->fresh())
        ->terminal_at->not->toBeNull()
        ->raw_payload->toBe('')
        ->and(SupportZammadWebhookInbox::query()->count())->toBe(1)
        ->and(SupportZammadWebhookDelivery::query()->count())
        ->toBe(
            SupportZammadWebhookInboxRecorder::MAX_DELIVERY_ALIASES_PER_PAYLOAD,
        );
});

it('expires an old unmatched webhook while preserving old mapped work', function () {
    config()->set(
        'services.zammad.unmatched_webhook_retention_hours',
        24,
    );
    $expiredRaw = json_encode([
        'ticket' => ['id' => 987, 'title' => 'Nie zugeordnet'],
    ], JSON_THROW_ON_ERROR);
    $mappedRaw = json_encode([
        'ticket' => [
            'id' => 988,
            'title' => 'Spät, aber zugeordnet',
            'state' => 'open',
            'updated_at' => now()->utc()->subMinute()->toIso8601String(),
        ],
    ], JSON_THROW_ON_ERROR);
    $expired = app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-expired-unmatched',
        '987',
        $expiredRaw,
    );
    $mapped = app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-old-mapped',
        '988',
        $mappedRaw,
    );
    SupportZammadWebhookInbox::query()
        ->whereKey([$expired->getKey(), $mapped->getKey()])
        ->update([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ZAMMAD-OLD-MAPPED',
        'subject' => 'Spät, aber zugeordnet',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '988',
        'sync_status' => 'synced',
    ]);
    Bus::fake([ProcessSupportZammadWebhookInbox::class]);

    expect(app(
        SupportZammadWebhookInboxDispatcher::class,
    )->dispatchPending())->toBe(1);

    expect($expired->fresh())
        ->processed_at->toBeNull()
        ->terminal_at->not->toBeNull()
        ->last_error->toContain('maximalen Wartezeit')
        ->raw_payload->toBe('')
        ->and($mapped->fresh())
        ->processed_at->toBeNull()
        ->terminal_at->toBeNull()
        ->raw_payload->toBe($mappedRaw);
    Bus::assertDispatched(
        ProcessSupportZammadWebhookInbox::class,
        fn (ProcessSupportZammadWebhookInbox $job): bool => $job->inboxId
            === $mapped->getKey(),
    );
});

it('keeps an inbox entry pending across a mapping race and rejects delivery ID reuse', function () {
    $payload = [
        'ticket' => [
            'id' => 981,
            'title' => 'Zuordnungsrennen',
            'state' => 'open',
            'updated_at' => now()->utc()->subMinute()->toIso8601String(),
        ],
    ];
    erinRuntimeZammadWebhook($this, $payload, 'delivery-mapping-race')
        ->assertAccepted();
    $inbox = SupportZammadWebhookInbox::query()->sole();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ZAMMAD-EARLY-02',
        'subject' => 'Zuordnungsrennen',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '981',
        'sync_status' => 'synced',
    ]);

    $ticket->update(['external_system' => null, 'external_id' => null]);
    (new ProcessSupportZammadWebhookInbox($inbox->getKey()))
        ->handle(app(ZammadWebhookSignature::class));

    expect($inbox->fresh())
        ->attempts->toBe(0)
        ->processed_at->toBeNull()
        ->terminal_at->toBeNull()
        ->locked_at->toBeNull();

    $ticket->update(['external_system' => 'zammad', 'external_id' => '981']);
    $this->travel(31)->seconds();
    (new ProcessSupportZammadWebhookInbox($inbox->getKey()))
        ->handle(app(ZammadWebhookSignature::class));

    expect($inbox->fresh())
        ->attempts->toBe(1)
        ->processed_at->not->toBeNull()
        ->terminal_at->toBeNull()
        ->raw_payload->toBe('');

    $conflictingPayload = json_encode([
        'ticket' => ['id' => 982, 'title' => 'Widerspruch'],
    ], JSON_THROW_ON_ERROR);
    expect(fn () => app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-mapping-race',
        '982',
        $conflictingPayload,
    ))->toThrow(RuntimeException::class, 'widersprüchlichem Inhalt')
        ->and(SupportZammadWebhookInbox::query()->count())->toBe(1);
});

it('terminally rejects a tampered inbox payload without replaying it', function () {
    $payload = [
        'ticket' => [
            'id' => 984,
            'title' => 'Integritätsprüfung',
            'state' => 'open',
            'updated_at' => now()->utc()->subMinute()->toIso8601String(),
        ],
    ];
    erinRuntimeZammadWebhook($this, $payload, 'delivery-integrity-check')
        ->assertAccepted();
    $inbox = SupportZammadWebhookInbox::query()->sole();
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ZAMMAD-INTEGRITY',
        'subject' => 'Integritätsprüfung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '984',
        'sync_status' => 'synced',
    ]);
    $inbox->update(['raw_payload' => '{"ticket":{"id":984,"title":"Manipuliert"}}']);

    (new ProcessSupportZammadWebhookInbox($inbox->getKey()))
        ->handle(app(ZammadWebhookSignature::class));

    expect($inbox->fresh())
        ->attempts->toBe(1)
        ->processed_at->toBeNull()
        ->terminal_at->not->toBeNull()
        ->last_error->toContain('Integritätsprüfung')
        ->raw_payload->toBe('')
        ->and(IntegrationReceipt::query()->count())->toBe(0)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
});

it('terminally stops replay after the durable maximum attempt count', function () {
    $rawPayload = json_encode([
        'ticket' => [
            'id' => 985,
            'title' => 'Dauerhafter Providerfehler',
            'state' => 'open',
            'updated_at' => now()->utc()->subMinute()->toIso8601String(),
        ],
    ], JSON_THROW_ON_ERROR);
    $inbox = app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-max-attempts',
        '985',
        $rawPayload,
    );
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ZAMMAD-MAX-ATTEMPTS',
        'subject' => 'Dauerhafter Providerfehler',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '985',
        'sync_status' => 'synced',
    ]);
    config()->set('services.zammad.enabled', false);
    $job = new ProcessSupportZammadWebhookInbox($inbox->getKey());

    for ($attempt = 1; $attempt < ProcessSupportZammadWebhookInbox::MAX_ATTEMPTS; $attempt++) {
        expect(fn () => $job->handle(app(ZammadWebhookSignature::class)))
            ->toThrow(HttpException::class);
        $this->travel(31)->seconds();
    }

    $job->handle(app(ZammadWebhookSignature::class));

    expect($inbox->fresh())
        ->attempts->toBe(ProcessSupportZammadWebhookInbox::MAX_ATTEMPTS)
        ->processed_at->toBeNull()
        ->terminal_at->not->toBeNull()
        ->last_error->toContain('maximalen Anzahl')
        ->raw_payload->toBe('');

    Bus::fake([ProcessSupportZammadWebhookInbox::class]);
    expect(app(
        SupportZammadWebhookInboxDispatcher::class,
    )->dispatchPending())->toBe(0);
    Bus::assertNothingDispatched();
});

it('terminally closes a stale max-attempt claim after a worker kill window', function () {
    $rawPayload = json_encode([
        'ticket' => [
            'id' => 986,
            'title' => 'Unterbrochener Maximalversuch',
        ],
    ], JSON_THROW_ON_ERROR);
    $inbox = app(SupportZammadWebhookInboxRecorder::class)->record(
        'delivery-stale-max-attempt',
        '986',
        $rawPayload,
    );
    $inbox->update([
        'attempts' => ProcessSupportZammadWebhookInbox::MAX_ATTEMPTS,
        'locked_at' => now()->subMinutes(11),
    ]);
    Bus::fake([ProcessSupportZammadWebhookInbox::class]);

    expect(app(
        SupportZammadWebhookInboxDispatcher::class,
    )->dispatchPending())->toBe(0);

    expect($inbox->fresh())
        ->attempts->toBe(ProcessSupportZammadWebhookInbox::MAX_ATTEMPTS)
        ->processed_at->toBeNull()
        ->terminal_at->not->toBeNull()
        ->locked_at->toBeNull()
        ->last_error->toContain('unterbrochenen Maximalversuch')
        ->raw_payload->toBe('');
    Bus::assertNothingDispatched();
});

it('never exposes an internal support attachment to a ticket participant', function () {
    $requester = User::factory()->create(['email_verified_at' => now()]);
    $support = User::factory()->create([
        'role' => UserRole::Support,
        'email_verified_at' => now(),
    ]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-INTERNAL-ATTACHMENT-01',
        'subject' => 'Interne Supportnotiz',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $support->getKey(),
        'body' => 'Nur für den Support.',
        'source' => 'erin',
        'delivery_status' => 'local_only',
        'is_internal' => true,
    ]);
    $path = sprintf(
        'support-tickets/%d/erin/internal.pdf',
        $ticket->getKey(),
    );
    Storage::disk('private')->put($path, 'internal-content');
    $attachment = $message->files()->create([
        'uploaded_by' => $support->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'internal.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('internal-content'),
        'checksum_sha256' => hash('sha256', 'internal-content'),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $url = URL::temporarySignedRoute(
        'support.attachments.download',
        now()->addMinutes(10),
        ['attachment' => $attachment->getKey()],
    );

    $this->actingAs($requester)->get($url)->assertForbidden();
    $this->actingAs($support)
        ->get($url)
        ->assertOk()
        ->assertStreamedContent('internal-content');
});

it('requires production staff 2FA for signed support downloads without blocking users', function () {
    $requester = User::factory()->create(['email_verified_at' => now()]);
    $support = User::factory()->create([
        'role' => UserRole::Support,
        'email_verified_at' => now(),
        'two_factor_confirmed_at' => null,
    ]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-STAFF-2FA',
        'subject' => '2FA-Downloadgrenze',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Öffentlicher Anhang.',
        'source' => 'erin',
        'delivery_status' => 'local_only',
        'is_internal' => false,
    ]);
    $path = sprintf(
        'support-tickets/%d/erin/public.pdf',
        $ticket->getKey(),
    );
    Storage::disk('private')->put($path, 'public-content');
    $attachment = $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'public.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('public-content'),
        'checksum_sha256' => hash('sha256', 'public-content'),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $url = URL::temporarySignedRoute(
        'support.attachments.download',
        now()->addMinutes(10),
        ['attachment' => $attachment->getKey()],
    );
    $originalEnvironment = app()->environment();
    $originalDemoMode = config('app.demo_mode');
    app()->instance('env', 'production');
    config()->set('app.demo_mode', false);

    try {
        $this->actingAs($support)
            ->get($url)
            ->assertRedirect(route('security.edit'));
        $this->actingAs($requester)
            ->get($url)
            ->assertOk()
            ->assertStreamedContent('public-content');
    } finally {
        app()->instance('env', $originalEnvironment);
        config()->set('app.demo_mode', $originalDemoMode);
    }
});

it('terminally completes notification effects whose deleted recipient was nulled', function () {
    Notification::fake();
    $requester = User::factory()->create();
    $replacement = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-OUTBOX-DELETED-RECIPIENT',
        'subject' => 'Gelöschter Empfänger',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'body' => 'Antwort nach späterer Nutzerlöschung.',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'is_internal' => false,
    ]);
    app(SupportWebhookOutboxRecorder::class)->record($message, [], true);
    $ticket->update(['requester_id' => $replacement->getKey()]);
    $requester->delete();

    $entries = SupportWebhookOutbox::query()
        ->where('effect', 'like', 'notification_%')
        ->get();
    expect($entries)->toHaveCount(4)
        ->and($entries->pluck('recipient_id')->unique()->all())->toBe([null]);

    foreach ($entries as $entry) {
        (new ProcessSupportWebhookOutbox($entry->getKey()))
            ->handle(app(SupportWebhookOutboxEffects::class));
    }

    expect(
        SupportWebhookOutbox::query()
            ->where('effect', 'like', 'notification_%')
            ->whereNull('processed_at')
            ->count(),
    )->toBe(0)
        ->and(
            SupportWebhookOutbox::query()
                ->where('effect', 'like', 'notification_%')
                ->where('attempts', 1)
                ->count(),
        )->toBe(4);
    Notification::assertNothingSent();
});

it('does not redispatch terminal attachment failures for tickets or replies', function () {
    Bus::fake([
        SyncSupportMessageToProvider::class,
        SyncSupportTicketToProvider::class,
    ]);
    $requester = User::factory()->create();
    $pendingTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-TERMINAL-ATTACHMENT-01',
        'subject' => 'Fehlgeschlagener Eröffnungsanhang',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'pending',
    ]);
    $opening = $pendingTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Anhang konnte nicht geprüft werden.',
        'source' => 'erin',
        'delivery_status' => 'failed',
        'is_internal' => false,
    ]);
    $opening->files()->create([
        'source' => 'erin',
        'original_name' => 'scan-fehler.pdf',
        'scan_result' => 'scan_failed',
        'scan_completed_at' => now(),
    ]);

    $syncedTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-TERMINAL-ATTACHMENT-02',
        'subject' => 'Infizierter Antwortanhang',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '983',
        'sync_status' => 'synced',
    ]);
    $syncedTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Bereits zugestellte Eröffnung.',
        'source' => 'erin',
        'delivery_status' => 'delivered',
        'external_article_id' => '9831',
        'is_internal' => false,
    ]);
    $reply = $syncedTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Antwort mit infiziertem Anhang.',
        'source' => 'erin',
        'delivery_status' => 'failed',
        'is_internal' => false,
    ]);
    $reply->files()->create([
        'source' => 'erin',
        'original_name' => 'infiziert.pdf',
        'scan_result' => 'infected',
        'scan_completed_at' => now(),
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->twice()->andReturnTrue();
    $provider->shouldNotReceive('createTicket');
    $provider->shouldNotReceive('findTicket');
    $provider->shouldNotReceive('createMessage');
    $provider->shouldNotReceive('findMessage');

    (new SyncSupportTicketToProvider($pendingTicket->getKey()))
        ->handle($provider);
    (new SyncSupportMessageToProvider($reply->getKey()))
        ->handle($provider);

    expect($pendingTicket->fresh())
        ->sync_status->toBe('failed')
        ->external_reconcile_not_before->toBeNull()
        ->and($opening->fresh())
        ->delivery_status->toBe('failed')
        ->external_reconcile_not_before->toBeNull()
        ->and($reply->fresh())
        ->delivery_status->toBe('failed')
        ->external_reconcile_not_before->toBeNull()
        ->and(app(SupportOutboundReconciliationDispatcher::class)->dispatchDue())
        ->toBe(['tickets' => 0, 'messages' => 0]);
    Bus::assertNothingDispatched();
});
