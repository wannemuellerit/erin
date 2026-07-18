<?php

use App\Contracts\TicketingProvider;
use App\Enums\SupportTicketStatus;
use App\Events\SupportTicketMessageCreated;
use App\Jobs\ScanSupportTicketAttachment;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportZammadArticleReceipt;
use App\Models\User;
use App\Services\Ticketing\SupportOutboundReconciliationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('recovers committed pending tickets and replies without posting the opening message twice', function () {
    Bus::fake([
        SyncSupportMessageToProvider::class,
        SyncSupportTicketToProvider::class,
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-PENDING-RECOVERY-01',
        'subject' => 'Commit vor Queue-Dispatch',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'pending',
    ]);
    $openingMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Diese Eröffnungsnachricht wurde bereits committed.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);
    $dispatcher = app(SupportOutboundReconciliationDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe([
        'tickets' => 1,
        'messages' => 0,
    ]);
    Bus::assertDispatched(
        SyncSupportTicketToProvider::class,
        fn (SyncSupportTicketToProvider $job): bool => $job->ticketId
            === $ticket->getKey(),
    );
    Bus::assertNotDispatched(
        SyncSupportMessageToProvider::class,
        fn (SyncSupportMessageToProvider $job): bool => $job->messageId
            === $openingMessage->getKey(),
    );

    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->twice()->andReturnTrue();
    $provider->shouldReceive('createTicket')
        ->once()
        ->withArgs(
            fn (
                SupportTicket $createdTicket,
                SupportTicketMessage $createdMessage,
            ): bool => $createdTicket->is($ticket)
                && $createdMessage->is($openingMessage),
        )
        ->andReturn([
            'external_id' => '970',
            'external_number' => '970',
            'external_article_id' => '9701',
        ]);
    $provider->shouldReceive('createMessage')
        ->once()
        ->andReturn(['external_article_id' => '9702']);

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);
    $reply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Auch diese Antwort wurde vor dem Queue-Dispatch committed.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);

    expect($dispatcher->dispatchDue())->toBe([
        'tickets' => 0,
        'messages' => 1,
    ]);
    Bus::assertDispatched(
        SyncSupportMessageToProvider::class,
        fn (SyncSupportMessageToProvider $job): bool => $job->messageId
            === $reply->getKey(),
    );

    (new SyncSupportMessageToProvider($reply->getKey()))->handle($provider);

    expect($openingMessage->fresh())
        ->external_article_id->toBe('9701')
        ->delivery_status->toBe('delivered')
        ->and($reply->fresh())
        ->external_article_id->toBe('9702')
        ->delivery_status->toBe('delivered')
        ->and(SupportZammadArticleReceipt::query()
            ->orderBy('external_article_id')
            ->pluck('external_article_id')
            ->all())
        ->toBe(['9701', '9702']);
});

it('terminally marks pending work local-only when the provider is disabled', function () {
    Bus::fake([
        SyncSupportMessageToProvider::class,
        SyncSupportTicketToProvider::class,
    ]);
    $requester = User::factory()->create();
    $pendingTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-PENDING-DISABLED-01',
        'subject' => 'Deaktivierter Provider beim Ticket',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'pending',
    ]);
    $opening = $pendingTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Lokale Eröffnung.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);
    $unsentReply = $pendingTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Lokale Antwort.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);
    $syncedTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-PENDING-DISABLED-02',
        'subject' => 'Deaktivierter Provider bei Antwort',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'synced',
        'external_system' => 'zammad',
        'external_id' => '971',
    ]);
    $syncedTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Bereits zugestellte Eröffnung.',
        'source' => 'erin',
        'delivery_status' => 'delivered',
        'external_article_id' => '9711',
        'is_internal' => false,
    ]);
    $pendingReply = $syncedTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Noch nicht zugestellte Antwort.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);
    $dispatcher = app(SupportOutboundReconciliationDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe([
        'tickets' => 1,
        'messages' => 1,
    ]);

    $disabledProvider = Mockery::mock(TicketingProvider::class);
    $disabledProvider->shouldReceive('enabled')->twice()->andReturnFalse();
    $disabledProvider->shouldNotReceive('createTicket');
    $disabledProvider->shouldNotReceive('createMessage');
    (new SyncSupportTicketToProvider($pendingTicket->getKey()))
        ->handle($disabledProvider);
    (new SyncSupportMessageToProvider($pendingReply->getKey()))
        ->handle($disabledProvider);

    expect($pendingTicket->fresh()->sync_status)->toBe('disabled')
        ->and($opening->fresh()->delivery_status)->toBe('local_only')
        ->and($unsentReply->fresh()->delivery_status)->toBe('local_only')
        ->and($pendingReply->fresh()->delivery_status)->toBe('local_only')
        ->and($dispatcher->dispatchDue())->toBe([
            'tickets' => 0,
            'messages' => 0,
        ]);
    Bus::assertDispatchedTimes(SyncSupportTicketToProvider::class, 1);
    Bus::assertDispatchedTimes(SyncSupportMessageToProvider::class, 1);
});

it('maps a ticket with a clean opening while keeping a later terminal reply unsent', function () {
    Bus::fake([
        SyncSupportMessageToProvider::class,
        SyncSupportTicketToProvider::class,
    ]);
    Event::fake([SupportTicketMessageCreated::class]);
    Storage::fake('private');
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-PENDING-RECOVERY-TERMINAL-REPLY',
        'subject' => 'Verlorener Ticket-Queuejob',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'pending',
    ]);
    $opening = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Saubere Eröffnungsnachricht.',
        'source' => 'erin',
        'delivery_status' => 'pending',
        'is_internal' => false,
    ]);
    $opening->files()->create([
        'source' => 'erin',
        'original_name' => 'sauber.pdf',
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $terminalReply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Spätere Antwort mit fehlgeschlagenem Scan.',
        'source' => 'erin',
        'delivery_status' => 'failed',
        'is_internal' => false,
    ]);
    $terminalPath = 'support-tickets/recovery/unsicher.pdf';
    Storage::disk('private')->put($terminalPath, 'nicht-geprüft');
    $terminalAttachment = $terminalReply->files()->create([
        'source' => 'erin',
        'disk' => 'private',
        'path' => $terminalPath,
        'original_name' => 'unsicher.pdf',
        'scan_result' => 'pending',
    ]);
    (new ScanSupportTicketAttachment($terminalAttachment->getKey()))
        ->failed(new RuntimeException('ClamAV dauerhaft nicht erreichbar'));
    $dispatcher = app(SupportOutboundReconciliationDispatcher::class);

    expect($ticket->fresh()->sync_status)->toBe('pending')
        ->and($terminalReply->fresh()->delivery_status)->toBe('failed')
        ->and($terminalAttachment->fresh()->scan_result)->toBe('scan_failed')
        ->and($dispatcher->dispatchDue())->toBe([
            'tickets' => 1,
            'messages' => 0,
        ]);
    Bus::assertDispatched(
        SyncSupportTicketToProvider::class,
        fn (SyncSupportTicketToProvider $job): bool => $job->ticketId
            === $ticket->getKey(),
    );
    Bus::assertNotDispatched(
        SyncSupportMessageToProvider::class,
        fn (SyncSupportMessageToProvider $job): bool => $job->messageId
            === $terminalReply->getKey(),
    );

    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->once()->andReturnTrue();
    $provider->shouldReceive('createTicket')
        ->once()
        ->withArgs(
            fn (
                SupportTicket $createdTicket,
                SupportTicketMessage $createdMessage,
            ): bool => $createdTicket->is($ticket)
                && $createdMessage->is($opening),
        )
        ->andReturn([
            'external_id' => '972',
            'external_article_id' => '9721',
        ]);
    $provider->shouldNotReceive('createMessage');

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);

    expect($ticket->fresh())
        ->external_id->toBe('972')
        ->sync_status->toBe('synced')
        ->and($opening->fresh())
        ->external_article_id->toBe('9721')
        ->delivery_status->toBe('delivered')
        ->and($terminalReply->fresh())
        ->external_article_id->toBeNull()
        ->delivery_status->toBe('failed')
        ->and($dispatcher->dispatchDue())
        ->toBe(['tickets' => 0, 'messages' => 0]);
    Bus::assertDispatchedTimes(SyncSupportTicketToProvider::class, 1);
    Bus::assertNotDispatched(
        SyncSupportMessageToProvider::class,
        fn (SyncSupportMessageToProvider $job): bool => $job->messageId
            === $terminalReply->getKey(),
    );
});
