<?php

use App\Contracts\TicketingProvider;
use App\Enums\CompanyMemberRole;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Events\SupportTicketMessageCreated;
use App\Jobs\ImportZammadAttachment;
use App\Jobs\ProcessSupportWebhookOutbox;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\ActivityEntry;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\IntegrationReceipt;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\SupportWebhookOutbox;
use App\Models\SupportZammadArticleReceipt;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Billing\IntegrationEventGuard;
use App\Services\Documents\ClamAvScanner;
use App\Services\Ticketing\SupportOutboundReconciliationDispatcher;
use App\Services\Ticketing\SupportWebhookOutboxDispatcher;
use App\Services\Ticketing\SupportWebhookOutboxEffects;
use App\Services\Ticketing\SupportWebhookOutboxRecorder;
use App\Services\Ticketing\ZammadMessageMarker;
use App\Services\Ticketing\ZammadTicketingProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(RefreshDatabase::class);

function erinConfigureZammad(): void
{
    config()->set('app.env', 'testing');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set(
        'services.zammad.webhook_callback_url',
        'https://erin.example.test/integrations/zammad/webhook',
    );
    config()->set('services.zammad.allow_local_http', false);
    config()->set('services.zammad.local_http_hosts', []);
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set(
        'services.zammad.webhook_secret',
        'zammad-webhook-secret-with-32-chars',
    );
    config()->set(
        'services.zammad.message_marker_secret',
        'zammad-message-marker-secret-current',
    );
    config()->set('services.zammad.previous_message_marker_secrets', []);
    config()->set('services.zammad.group', 'Erin Support');
    config()->set('services.zammad.reconcile_initial_delay_seconds', 1);
    config()->set('services.zammad.reconcile_interval_seconds', 1);
    config()->set('services.zammad.reconcile_required_misses', 2);
}

/**
 * @return array{user: User, company: Company}
 */
function erinSupportEmployer(): array
{
    $user = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return compact('user', 'company');
}

/**
 * @param  array<string, mixed>  $payload
 */
function erinZammadWebhook(
    TestCase $test,
    array $payload,
    string $delivery,
    ?string $signature = null,
): TestResponse {
    if (
        is_array($payload['ticket'] ?? null)
        && ! array_key_exists('updated_at', $payload['ticket'])
    ) {
        $payload['ticket']['updated_at'] = '2026-07-18T12:00:00.000Z';
    }
    if (
        is_array($payload['ticket']['article'] ?? null)
        && ! array_key_exists('ticket_id', $payload['ticket']['article'])
    ) {
        $payload['ticket']['article']['ticket_id'] = $payload['ticket']['id'] ?? null;
    }
    if (
        is_array($payload['ticket']['article'] ?? null)
        && ! array_key_exists('updated_at', $payload['ticket']['article'])
    ) {
        $payload['ticket']['article']['updated_at'] = $payload['ticket']['updated_at']
            ?? '2026-07-18T12:00:00.000Z';
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature ??= 'sha1='.hash_hmac(
        'sha1',
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
            'HTTP_X_ZAMMAD_DELIVERY' => $delivery,
            'CONTENT_TYPE' => 'application/json',
        ],
        $json,
    );
}

function erinRawZammadWebhook(
    TestCase $test,
    string $json,
    string $delivery,
    ?string $signature = null,
): TestResponse {
    $signature ??= 'sha1='.hash_hmac(
        'sha1',
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
            'HTTP_X_ZAMMAD_DELIVERY' => $delivery,
            'CONTENT_TYPE' => 'application/json',
        ],
        $json,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $without
 * @return array<string, mixed>
 */
function erinPublicZammadArticle(
    array $overrides = [],
    array $without = [],
): array {
    $article = array_replace([
        'id' => 9301,
        'ticket_id' => 830,
        'sender' => 'Agent',
        'internal' => false,
        'updated_at' => '2026-07-18T12:00:00.000Z',
        'body' => 'Öffentliche Antwort.',
    ], $overrides);

    foreach ($without as $key) {
        unset($article[$key]);
    }

    return $article;
}

it('synchronizes new tickets and replies with the authenticated Zammad API', function () {
    erinConfigureZammad();
    Http::fake([
        'https://zammad.example.test/api/v1/tickets' => Http::response([
            'id' => 501,
            'number' => '99001',
            'article_ids' => [8001],
        ]),
        'https://zammad.example.test/api/v1/ticket_articles' => Http::response([
            'id' => 8002,
        ]),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-TEST01',
        'subject' => 'Dokument lässt sich nicht hochladen',
        'priority' => 'high',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now(),
    ]);
    $firstMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Beim Hochladen erscheint ein Fehler.',
        'is_internal' => false,
    ]);
    $provider = new ZammadTicketingProvider;

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);

    expect($ticket->fresh())
        ->external_system->toBe('zammad')
        ->external_id->toBe('501')
        ->sync_status->toBe('synced')
        ->and($firstMessage->fresh())
        ->external_article_id->toBe('8001')
        ->delivery_status->toBe('delivered');

    $reply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Der Fehler besteht weiterhin.',
        'is_internal' => false,
    ]);
    (new SyncSupportMessageToProvider($reply->getKey()))->handle($provider);

    expect($reply->fresh())
        ->external_article_id->toBe('8002')
        ->delivery_status->toBe('delivered')
        ->and($reply->fresh()?->delivered_at)->not->toBeNull();

    Http::assertSent(function (ClientRequest $request) use ($firstMessage, $requester): bool {
        if ($request->url() !== 'https://zammad.example.test/api/v1/tickets') {
            return false;
        }

        return $request->hasHeader('Authorization', 'Token token=zammad-test-token')
            && $request['group'] === 'Erin Support'
            && $request['customer_id'] === 'guess:'.$requester->email
            && $request['article']['subject'] === app(ZammadMessageMarker::class)->for($firstMessage)
            && $request['article']['sender'] === 'Customer'
            && $request['article']['internal'] === false;
    });
    Http::assertSent(function (ClientRequest $request): bool {
        if ($request->url() !== 'https://zammad.example.test/api/v1/ticket_articles') {
            return false;
        }

        return $request['ticket_id'] === 501
            && $request['body'] === 'Der Fehler besteht weiterhin.'
            && $request['sender'] === 'Customer'
            && $request['internal'] === false;
    });
});

it('refuses unsafe Zammad endpoints and never forwards the API token across redirects', function () {
    erinConfigureZammad();
    config()->set('services.zammad.url', 'http://zammad.example.test');

    $provider = new ZammadTicketingProvider;

    expect($provider->enabled())->toBeFalse();

    config()->set('services.zammad.url', 'https://zammad.example.test');
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_articles/44' => Http::response(
            '',
            302,
            ['Location' => 'https://redirect.example.test/api/v1/ticket_articles/44'],
        ),
        '*' => Http::response(['id' => 44]),
    ]);

    expect(fn () => $provider->article('44'))->toThrow(RuntimeException::class);

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (ClientRequest $request): bool => str_contains($request->url(), 'redirect.example.test'),
    );
});

it('allows the exact local Zammad service host only outside production', function () {
    erinConfigureZammad();
    config()->set('app.env', 'local');
    config()->set('services.zammad.url', 'http://zammad:8080');
    config()->set('services.zammad.allow_local_http', true);
    config()->set('services.zammad.local_http_hosts', ['zammad', 'laravel']);

    $provider = new ZammadTicketingProvider;

    expect($provider->enabled())->toBeTrue();

    config()->set('services.zammad.url', 'http://zammad-postgresql:5432');

    expect($provider->enabled())->toBeFalse();

    config()->set('services.zammad.url', 'http://zammad:8080');
    config()->set('app.env', 'production');

    expect($provider->enabled())->toBeFalse();
});

it('reconciles uncertain Zammad writes instead of creating duplicate tickets or articles', function () {
    erinConfigureZammad();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-RECON1',
        'subject' => 'Unsichere Zustellung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'syncing',
        'last_reply_at' => now(),
    ]);
    $firstMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Die erste Antwort ist beim Provider möglicherweise angekommen.',
        'is_internal' => false,
    ]);
    $reply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Auch diese Nachricht muss ohne Duplikat abgeglichen werden.',
        'is_internal' => false,
        'delivery_status' => 'sending',
    ]);
    Http::fake([
        'https://zammad.example.test/api/v1/tickets/search*' => Http::response([
            'assets' => [
                'Ticket' => [
                    '912' => [
                        'id' => 912,
                        'number' => '99120',
                        'note' => 'Erin operation ticket:'.$ticket->number,
                    ],
                ],
            ],
        ]),
        'https://zammad.example.test/api/v1/tickets/912' => Http::response([
            'id' => 912,
            'number' => '99120',
            'article_ids' => [9911, 9999],
        ]),
        'https://zammad.example.test/api/v1/ticket_articles/by_ticket/912' => Http::response([
            [
                'id' => 9911,
                'ticket_id' => 912,
                'subject' => app(ZammadMessageMarker::class)->for($firstMessage),
                'body' => $firstMessage->body,
                'internal' => false,
                'sender' => 'Customer',
            ],
            [
                'id' => 9912,
                'ticket_id' => 912,
                'subject' => app(ZammadMessageMarker::class)->for($reply),
                'body' => $reply->body,
                'internal' => false,
                'sender' => 'Customer',
            ],
            [
                'id' => 9999,
                'ticket_id' => 912,
                'subject' => 'Antwort des Support-Teams',
                'body' => 'Diese zwischenzeitliche Antwort darf nicht als Eröffnungsartikel gelten.',
                'internal' => false,
                'sender' => 'Agent',
            ],
        ]),
    ]);
    $provider = new ZammadTicketingProvider;

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);
    $this->travel(2)->seconds();
    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);
    (new SyncSupportMessageToProvider($reply->getKey()))->handle($provider);
    $this->travel(2)->seconds();
    (new SyncSupportMessageToProvider($reply->getKey()))->handle($provider);

    expect($ticket->fresh())
        ->external_id->toBe('912')
        ->sync_status->toBe('synced')
        ->and($firstMessage->fresh())
        ->external_article_id->toBe('9911')
        ->delivery_status->toBe('delivered')
        ->and($reply->fresh())
        ->external_article_id->toBe('9912')
        ->delivery_status->toBe('delivered');

    Http::assertNotSent(
        fn (ClientRequest $request): bool => $request->method() === 'POST',
    );
});

it('recreates missing Zammad tickets and articles after reconciling a failed write', function () {
    erinConfigureZammad();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-RECON2',
        'subject' => 'Fehlgeschlagene Zustellung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'failed',
        'last_reply_at' => now(),
    ]);
    $firstMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Dieses Ticket fehlt nach dem ersten Zustellversuch in Zammad.',
        'is_internal' => false,
    ]);
    Http::fake([
        'https://zammad.example.test/api/v1/tickets/search*' => Http::response([
            'assets' => ['Ticket' => []],
        ]),
        'https://zammad.example.test/api/v1/tickets' => Http::response([
            'id' => 913,
            'number' => '99121',
            'article_ids' => [9921],
        ]),
        'https://zammad.example.test/api/v1/ticket_articles/by_ticket/913' => Http::response([]),
        'https://zammad.example.test/api/v1/ticket_articles' => Http::response([
            'id' => 9922,
        ]),
    ]);
    $provider = new ZammadTicketingProvider;

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);
    expect($ticket->fresh()->external_id)->toBeNull();
    $this->travel(2)->seconds();
    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);

    $reply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Auch dieser fehlende Artikel darf nach dem Abgleich erneut erstellt werden.',
        'is_internal' => false,
        'delivery_status' => 'failed',
    ]);
    (new SyncSupportMessageToProvider($reply->getKey()))->handle($provider);
    expect($reply->fresh()->external_article_id)->toBeNull();
    $this->travel(2)->seconds();
    (new SyncSupportMessageToProvider($reply->getKey()))->handle($provider);

    expect($ticket->fresh())
        ->external_id->toBe('913')
        ->sync_status->toBe('synced')
        ->and($firstMessage->fresh())
        ->external_article_id->toBe('9921')
        ->delivery_status->toBe('delivered')
        ->and($reply->fresh())
        ->external_article_id->toBe('9922')
        ->delivery_status->toBe('delivered')
        ->and(Http::recorded(
            fn (ClientRequest $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://zammad.example.test/api/v1/tickets',
        ))->toHaveCount(1)
        ->and(Http::recorded(
            fn (ClientRequest $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://zammad.example.test/api/v1/ticket_articles',
        ))->toHaveCount(1);
});

it('verifies Zammad HMAC signatures and imports each webhook reply exactly once', function () {
    erinConfigureZammad();
    Notification::fake();
    Bus::fake([ImportZammadAttachment::class]);
    $requester = User::factory()->create(['locale' => 'de']);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-TEST02',
        'subject' => 'Ursprünglicher Betreff',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '777',
        'sync_status' => 'synced',
        'last_reply_at' => now()->subHour(),
    ]);
    $payload = [
        'ticket' => [
            'id' => 777,
            'title' => 'Aktualisierter Betreff',
            'state' => 'open',
            'article' => [
                'id' => 9001,
                'sender' => 'Agent',
                'internal' => false,
                'body' => '<p>Wir haben das Problem <strong>behoben</strong>.<br>Bitte erneut testen.</p>',
                'attachments' => [[
                    'id' => 44,
                    'filename' => 'anleitung.pdf',
                    'size' => 12345,
                    'content' => 'darf-nicht-gespeichert-werden',
                ]],
            ],
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-9001', 'sha1=invalid')
        ->assertUnauthorized();

    erinZammadWebhook($this, $payload, 'delivery-9001')
        ->assertOk()
        ->assertJson(['accepted' => true, 'matched' => true]);
    erinZammadWebhook($this, $payload, 'delivery-9001')
        ->assertOk();

    $message = SupportTicketMessage::query()->sole();
    $attachment = SupportTicketAttachment::query()->sole();

    expect($ticket->fresh())
        ->subject->toBe('Aktualisierter Betreff')
        ->status->toBe(SupportTicketStatus::WaitingForCustomer)
        ->sync_status->toBe('synced')
        ->and($message)
        ->external_article_id->toBe('9001')
        ->source->toBe('zammad')
        ->delivery_status->toBe('delivered')
        ->body->toBe("Wir haben das Problem behoben.\nBitte erneut testen.")
        ->and($message->attachments)->toBeNull()
        ->and($attachment->external_id)->toBe('44')
        ->and($attachment->original_name)->toBe('anleitung.pdf')
        ->and($attachment->size_bytes)->toBe(12345)
        ->and($attachment->scan_result)->toBe('pending')
        ->and($attachment->path)->toBeNull()
        ->and(IntegrationReceipt::query()->count())->toBe(1)
        ->and(IntegrationReceipt::query()->sole()->status)->toBe('processed');

    Bus::assertDispatched(
        ImportZammadAttachment::class,
        fn (ImportZammadAttachment $job): bool => $job->attachmentId === $attachment->getKey(),
    );
    Notification::assertSentToTimes($requester, ActivityNotification::class, 3);
});

it('caps incoming Zammad attachments before creating records or import jobs', function () {
    erinConfigureZammad();
    config()->set('support.attachments.max_files', 2);
    Notification::fake();
    Bus::fake([ImportZammadAttachment::class]);
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-ATTACHMENT-LIMIT',
        'subject' => 'Begrenzte Zammad-Anhänge',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '778',
        'sync_status' => 'synced',
        'last_reply_at' => now(),
    ]);
    $payload = [
        'ticket' => [
            'id' => 778,
            'title' => 'Begrenzte Zammad-Anhänge',
            'state' => 'open',
            'article' => [
                'id' => 9002,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Drei Anhänge wurden hinzugefügt.',
                'attachments' => [
                    ['id' => 51, 'filename' => 'eins.pdf', 'size' => 100],
                    ['id' => 52, 'filename' => 'zwei.pdf', 'size' => 100],
                    ['id' => 53, 'filename' => 'drei.pdf', 'size' => 100],
                ],
            ],
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-9002')
        ->assertOk()
        ->assertJson(['accepted' => true, 'matched' => true]);

    expect(SupportTicketAttachment::query()->orderBy('id')->pluck('external_id')->all())
        ->toBe(['51', '52']);
    Bus::assertDispatchedTimes(ImportZammadAttachment::class, 2);
});

it('rejects oversized Zammad webhooks before processing or storing their payload', function () {
    erinConfigureZammad();
    config()->set('services.zammad.webhook_max_bytes', 128);
    $payload = [
        'ticket' => [
            'id' => 777,
            'title' => str_repeat('x', 256),
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-oversized')
        ->assertStatus(413);

    expect(IntegrationReceipt::query()->count())->toBe(0)
        ->and(config('telescope.ignore_paths'))->toContain('integrations/zammad/webhook');
});

it('uses the signed body instead of the unsigned delivery ID as event identity', function () {
    erinConfigureZammad();
    Notification::fake();
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-TEST03',
        'subject' => 'Idempotenz',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '778',
        'sync_status' => 'synced',
    ]);
    $payload = [
        'ticket' => [
            'id' => 778,
            'title' => 'Erste Version',
            'updated_at' => '2026-07-18T12:00:00.000Z',
            'article' => [
                'id' => 9002,
                'sender' => 'Agent',
                'internal' => true,
                'body' => 'Interne Notiz',
            ],
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-reused')->assertOk();
    $payload['ticket']['title'] = 'Signierte zweite Version';
    $payload['ticket']['updated_at'] = '2026-07-18T12:01:00.000Z';
    erinZammadWebhook($this, $payload, 'delivery-reused')->assertOk();

    expect(SupportTicket::query()->sole()->subject)->toBe('Signierte zweite Version')
        ->and(IntegrationReceipt::query()->count())->toBe(2)
        ->and(IntegrationReceipt::query()->where('status', 'processed')->count())->toBe(2)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
});

it('stores only a generic integration error when processing fails', function () {
    $payload = [
        'id' => 'delivery-sensitive-failure',
        'type' => 'zammad.ticket.updated',
        'ticket' => ['id' => 999],
    ];

    expect(fn () => app(IntegrationEventGuard::class)->once(
        'zammad',
        $payload,
        static fn () => throw new RuntimeException(
            'https://internal-zammad/api token=provider-secret',
        ),
    ))->toThrow(RuntimeException::class, 'provider-secret');

    $receipt = IntegrationReceipt::query()->sole();
    expect($receipt)
        ->status->toBe('failed')
        ->error_message->toBe(
            'Die Verarbeitung des Integrationseingangs ist fehlgeschlagen; technische Details stehen ausschließlich im geschützten Anwendungslog.',
        )
        ->error_message->not->toContain('internal-zammad')
        ->error_message->not->toContain('provider-secret');
});

it('fails closed for disabled, unsigned, incomplete and malformed Zammad webhooks', function () {
    erinConfigureZammad();
    $validPayload = ['ticket' => ['id' => 42, 'title' => 'Test']];

    config()->set('services.zammad.enabled', false);
    erinZammadWebhook($this, $validPayload, 'delivery-disabled')
        ->assertServiceUnavailable();

    erinConfigureZammad();
    erinRawZammadWebhook(
        $this,
        json_encode($validPayload, JSON_THROW_ON_ERROR),
        'delivery-unsigned',
        '',
    )->assertUnauthorized();
    erinZammadWebhook($this, $validPayload, '')
        ->assertUnprocessable();
    erinZammadWebhook($this, ['ticket' => 'not-an-object'], 'delivery-scalar')
        ->assertUnprocessable();
    erinZammadWebhook($this, ['ticket' => ['title' => 'ID fehlt']], 'delivery-no-id')
        ->assertUnprocessable();
    erinRawZammadWebhook($this, '{"ticket":', 'delivery-invalid-json')
        ->assertUnprocessable();

    expect(IntegrationReceipt::query()->count())->toBe(0)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
});

it('requires at least 32 bytes of webhook secret material at runtime', function () {
    erinConfigureZammad();
    config()->set('services.zammad.webhook_secret', str_repeat('x', 31));
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-WEAK-WEBHOOK-SECRET',
        'subject' => 'Schwaches Secret',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '829',
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 829,
            'title' => 'Darf nicht verarbeitet werden',
        ],
    ], 'delivery-weak-secret')->assertServiceUnavailable();

    expect(IntegrationReceipt::query()->count())->toBe(0)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
});

it('rejects malformed public Zammad article identity and provenance', function (
    array $overrides,
    array $without,
) {
    erinConfigureZammad();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-ARTICLE-VALIDATION',
        'subject' => 'Unverändert',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '830',
    ]);
    $json = json_encode([
        'ticket' => [
            'id' => 830,
            'title' => 'Darf nicht übernommen werden',
            'updated_at' => '2026-07-18T12:00:00.000Z',
            'article' => erinPublicZammadArticle($overrides, $without),
        ],
    ], JSON_THROW_ON_ERROR);

    erinRawZammadWebhook(
        $this,
        $json,
        'delivery-invalid-article',
    )->assertUnprocessable();

    expect($ticket->fresh()->subject)->toBe('Unverändert')
        ->and(IntegrationReceipt::query()->count())->toBe(0)
        ->and(SupportTicketMessage::query()->count())->toBe(0)
        ->and(SupportWebhookOutbox::query()->count())->toBe(0);
})->with([
    'Artikel-ID fehlt' => [[], ['id']],
    'internal fehlt' => [[], ['internal']],
    'internal ist ein String' => [['internal' => 'false'], []],
    'Ticket-ID fehlt' => [[], ['ticket_id']],
    'Ticket-ID gehört zu einem anderen Ticket' => [['ticket_id' => 831], []],
    'Absender fehlt' => [[], ['sender']],
    'Absender ist nicht Agent oder Customer' => [['sender' => 'System'], []],
    'Artikelzeit fehlt' => [[], ['updated_at']],
    'Artikelzeit ist ungültig' => [['updated_at' => 'keine-zeit'], []],
    'Text ist kein String' => [['body' => ['html' => '<p>Nein</p>']], []],
    'Anhang-ID ist ungültig' => [[
        'attachments' => [[
            'id' => '../9301',
            'filename' => 'manipuliert.pdf',
        ]],
    ], []],
]);

it('accepts a structurally valid internal Zammad note without importing it', function () {
    erinConfigureZammad();
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-VALID-INTERNAL',
        'subject' => 'Vor der internen Notiz',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '831',
    ]);
    $json = json_encode([
        'ticket' => [
            'id' => 831,
            'title' => 'Interne Statusaktualisierung',
            'state' => 'pending reminder',
            'updated_at' => '2026-07-18T12:00:00.000Z',
            'article' => [
                'id' => 9302,
                'ticket_id' => 831,
                'internal' => true,
                'body' => 'Nur für Zammad-Agenten sichtbar.',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    erinRawZammadWebhook(
        $this,
        $json,
        'delivery-valid-internal',
    )->assertOk();

    expect($ticket->fresh())
        ->subject->toBe('Interne Statusaktualisierung')
        ->status->toBe(SupportTicketStatus::InProgress)
        ->and(SupportTicketMessage::query()->count())->toBe(0)
        ->and(SupportWebhookOutbox::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('rejects invalid external Zammad ticket IDs before querying support data', function (mixed $invalidId) {
    erinConfigureZammad();

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => $invalidId,
            'title' => 'Ungültige externe ID',
        ],
    ], 'delivery-invalid-ticket-id')->assertUnprocessable();

    expect(IntegrationReceipt::query()->count())->toBe(0)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
})->with([
    'array' => [['nested' => 42]],
    'boolean' => [true],
    'floating point number' => [1.5],
    'zero' => ['0'],
    'negative number' => ['-1'],
    'query fragment' => ['1 OR 1=1'],
    'twenty digits' => [str_repeat('9', 20)],
]);

it('rejects invalid or oversized Zammad delivery tokens', function (string $delivery) {
    erinConfigureZammad();

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 42,
            'title' => 'Ungültige Delivery-ID',
        ],
    ], $delivery)->assertUnprocessable();

    expect(IntegrationReceipt::query()->count())->toBe(0);
})->with([
    'leading punctuation' => ['-not-allowed'],
    'space' => ['delivery has spaces'],
    'slash' => ['delivery/path'],
    'non ASCII' => ['zustellung-ä'],
    'more than 255 bytes' => [str_repeat('a', 256)],
]);

it('deduplicates the signed Zammad body even when the unsigned delivery ID changes', function () {
    erinConfigureZammad();
    Notification::fake();
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-REPLAY-ARTICLE',
        'subject' => 'Replay',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '811',
    ]);
    $payload = [
        'ticket' => [
            'id' => 811,
            'title' => 'Replay',
            'article' => [
                'id' => 9101,
                'ticket_id' => 811,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Diese Antwort darf nur einmal erscheinen.',
            ],
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-replay-a')->assertOk();
    erinZammadWebhook($this, $payload, 'delivery-replay-b')->assertOk();

    expect(SupportTicketMessage::query()->count())->toBe(1)
        ->and(IntegrationReceipt::query()->count())->toBe(1)
        ->and(IntegrationReceipt::query()->where('status', 'processed')->count())->toBe(1);
    Notification::assertSentToTimes($requester, ActivityNotification::class, 3);
});

it('keeps newer closed ticket state when an older signed open event arrives later', function () {
    erinConfigureZammad();
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-STALE-STATE',
        'subject' => 'Alter Betreff',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '819',
    ]);
    $newerPayload = [
        'ticket' => [
            'id' => 819,
            'title' => 'Final geschlossen',
            'state' => 'closed',
            'updated_at' => '2026-07-18T12:10:00.250Z',
        ],
    ];
    $olderPayload = [
        'ticket' => [
            'id' => 819,
            'title' => 'Veralteter offener Stand',
            'state' => 'open',
            'updated_at' => '2026-07-18T12:05:00.500Z',
        ],
    ];

    erinZammadWebhook($this, $newerPayload, 'delivery-state-newer')->assertOk();
    erinZammadWebhook($this, $olderPayload, 'delivery-state-older')->assertOk();
    erinZammadWebhook($this, $olderPayload, 'delivery-state-older-replay')->assertOk();

    expect($ticket->fresh())
        ->subject->toBe('Final geschlossen')
        ->status->toBe(SupportTicketStatus::Closed)
        ->external_updated_at_ms->toBe(
            CarbonImmutable::parse('2026-07-18T12:10:00.250Z')->getTimestampMs(),
        )
        ->and(IntegrationReceipt::query()->count())->toBe(2)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('advances article time independently without regressing an equal or older ticket state', function () {
    erinConfigureZammad();
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $stateAt = CarbonImmutable::now('UTC')->subHours(2)->startOfSecond();
    $newArticleAt = $stateAt->addMinutes(20);
    $olderArticleAt = $stateAt->addMinutes(10);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-INDEPENDENT-WATERMARKS',
        'subject' => 'Final geschlossen',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Closed,
        'external_system' => 'zammad',
        'external_id' => '828',
        'external_updated_at_ms' => $stateAt->getTimestampMs(),
        'last_reply_at' => $stateAt,
    ]);
    $initialLastReplyTimestamp = $ticket->fresh()->last_reply_at?->getTimestamp();

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 828,
            'title' => 'Gleich alter offener Stand',
            'state' => 'open',
            'updated_at' => $stateAt->toISOString(),
            'article' => [
                'id' => 9291,
                'ticket_id' => 828,
                'sender' => 'Agent',
                'internal' => false,
                'updated_at' => $newArticleAt->toISOString(),
                'body' => 'Neue Antwort zu einem unveränderten Ticketstand.',
            ],
        ],
    ], 'delivery-watermark-new-article')->assertOk();

    $advancedTicket = $ticket->fresh();
    expect($advancedTicket)
        ->subject->toBe('Final geschlossen')
        ->status->toBe(SupportTicketStatus::Closed)
        ->external_updated_at_ms->toBe($stateAt->getTimestampMs())
        ->external_last_article_at_ms->toBe($newArticleAt->getTimestampMs())
        ->and($advancedTicket->last_reply_at?->getTimestamp() > $initialLastReplyTimestamp)
        ->toBeTrue();
    $advancedLastReplyTimestamp = $advancedTicket->last_reply_at?->getTimestamp();

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 828,
            'title' => 'Noch älterer offener Stand',
            'state' => 'open',
            'updated_at' => $stateAt->subMinute()->toISOString(),
            'article' => [
                'id' => 9292,
                'ticket_id' => 828,
                'sender' => 'Agent',
                'internal' => false,
                'updated_at' => $newArticleAt->toISOString(),
                'body' => 'Gleich alter Artikel-Watermark.',
            ],
        ],
    ], 'delivery-watermark-equal-article')->assertOk();
    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 828,
            'title' => 'Veralteter offener Stand',
            'state' => 'open',
            'updated_at' => $stateAt->subMinutes(2)->toISOString(),
            'article' => [
                'id' => 9293,
                'ticket_id' => 828,
                'sender' => 'Agent',
                'internal' => false,
                'updated_at' => $olderArticleAt->toISOString(),
                'body' => 'Älterer Artikel-Watermark.',
            ],
        ],
    ], 'delivery-watermark-older-article')->assertOk();

    expect($ticket->fresh())
        ->subject->toBe('Final geschlossen')
        ->status->toBe(SupportTicketStatus::Closed)
        ->external_updated_at_ms->toBe($stateAt->getTimestampMs())
        ->external_last_article_at_ms->toBe($newArticleAt->getTimestampMs())
        ->and($ticket->fresh()?->last_reply_at?->getTimestamp())
        ->toBe($advancedLastReplyTimestamp)
        ->and(SupportTicketMessage::query()->count())->toBe(3);
});

it('requires a valid provider update timestamp before changing a matched ticket', function (
    mixed $updatedAt,
) {
    erinConfigureZammad();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-TIMESTAMP',
        'subject' => 'Unverändert',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '820',
    ]);
    $payload = [
        'ticket' => [
            'id' => 820,
            'title' => 'Darf nicht übernommen werden',
        ],
    ];
    if ($updatedAt !== null) {
        $payload['ticket']['updated_at'] = $updatedAt;
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    erinRawZammadWebhook($this, $json, 'delivery-invalid-provider-time')
        ->assertUnprocessable();

    expect($ticket->fresh())
        ->subject->toBe('Unverändert')
        ->external_updated_at_ms->toBeNull()
        ->and(IntegrationReceipt::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'invalid' => ['not-a-date'],
    'far future' => ['2099-01-01T00:00:00.000Z'],
]);

it('dispatches Zammad agent notifications only after the webhook transaction commits', function () {
    erinConfigureZammad();
    Bus::fake([ImportZammadAttachment::class]);
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-AFTER-COMMIT',
        'subject' => 'Transaktionsgrenze',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '825',
    ]);
    $activity = Mockery::mock(ActivityRecorder::class);
    $activity->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Erzwungener Rollback nach Callback-Registrierung.'));
    app()->instance(ActivityRecorder::class, $activity);

    $this->withoutExceptionHandling();
    expect(fn () => erinZammadWebhook($this, [
        'ticket' => [
            'id' => 825,
            'title' => 'Transaktionsgrenze',
            'state' => 'open',
            'updated_at' => '2026-07-18T12:15:00.000Z',
            'article' => [
                'id' => 9204,
                'ticket_id' => 825,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Diese Antwort wird vollständig zurückgerollt.',
                'attachments' => [[
                    'id' => 7204,
                    'filename' => 'rollback.pdf',
                    'size' => 128,
                ]],
            ],
        ],
    ], 'delivery-after-commit-rollback'))->toThrow(
        RuntimeException::class,
        'Erzwungener Rollback',
    );

    expect(SupportTicketMessage::query()->count())->toBe(0)
        ->and(SupportTicketAttachment::query()->count())->toBe(0)
        ->and(ActivityEntry::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->sole()->status)->toBe('failed');
    Bus::assertNotDispatched(ImportZammadAttachment::class);
    Event::assertNotDispatched(SupportTicketMessageCreated::class);
    Notification::assertNothingSent();
});

it('replays durable webhook effects after the post-commit queue dispatcher was unavailable', function () {
    erinConfigureZammad();
    Bus::fake([ImportZammadAttachment::class]);
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-OUTBOX-QUEUE-RETRY',
        'subject' => 'Queue-Ausfall nach Commit',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '833',
    ]);
    $payload = [
        'ticket' => [
            'id' => 833,
            'title' => 'Queue-Ausfall nach Commit',
            'state' => 'open',
            'article' => [
                'id' => 9304,
                'ticket_id' => 833,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Diese Antwort bleibt trotz Redis-Ausfall erhalten.',
                'attachments' => [[
                    'id' => 7304,
                    'filename' => 'redis-retry.pdf',
                    'size' => 128,
                ]],
            ],
        ],
    ];
    $dispatcher = Mockery::mock(SupportWebhookOutboxDispatcher::class);
    $dispatcher->shouldReceive('dispatchForTicket')
        ->once()
        ->andThrow(new RuntimeException('Redis ist nach dem Commit nicht erreichbar.'));
    app()->instance(SupportWebhookOutboxDispatcher::class, $dispatcher);

    $this->withoutExceptionHandling();
    expect(fn () => erinZammadWebhook(
        $this,
        $payload,
        'delivery-outbox-redis-first',
    ))->toThrow(RuntimeException::class, 'Redis');

    expect(SupportTicketMessage::query()->count())->toBe(1)
        ->and(SupportTicketAttachment::query()->count())->toBe(1)
        ->and(IntegrationReceipt::query()->sole()->status)->toBe('processed')
        ->and(SupportWebhookOutbox::query()->count())->toBe(6)
        ->and(SupportWebhookOutbox::query()->whereNull('processed_at')->count())->toBe(6);
    Bus::assertNotDispatched(ImportZammadAttachment::class);
    Event::assertNotDispatched(SupportTicketMessageCreated::class);
    Notification::assertNothingSent();

    app()->forgetInstance(SupportWebhookOutboxDispatcher::class);
    erinZammadWebhook($this, $payload, 'delivery-outbox-redis-replay')
        ->assertOk();
    erinZammadWebhook($this, $payload, 'delivery-outbox-redis-third')
        ->assertOk();

    $attachment = SupportTicketAttachment::query()->sole();
    expect(SupportTicketMessage::query()->count())->toBe(1)
        ->and(SupportTicketAttachment::query()->count())->toBe(1)
        ->and(IntegrationReceipt::query()->count())->toBe(1)
        ->and(SupportWebhookOutbox::query()->count())->toBe(6)
        ->and(SupportWebhookOutbox::query()->whereNotNull('processed_at')->count())->toBe(6);
    Bus::assertDispatchedTimes(ImportZammadAttachment::class, 1);
    Bus::assertDispatched(
        ImportZammadAttachment::class,
        fn (ImportZammadAttachment $job): bool => $job->attachmentId === $attachment->getKey(),
    );
    Event::assertDispatchedTimes(SupportTicketMessageCreated::class, 1);
    Notification::assertSentToTimes($requester, ActivityNotification::class, 3);
});

it('treats a complete outbox schema as installed when the migration repository record is missing', function () {
    $migration = require database_path(
        'migrations/2026_07_18_180000_create_support_webhook_outbox.php',
    );

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn(
        'support_tickets',
        'external_last_article_at_ms',
    ))->toBeTrue()
        ->and(Schema::hasTable('support_webhook_outbox'))->toBeTrue()
        ->and(Schema::hasIndex(
            'support_webhook_outbox',
            'support_webhook_outbox_deduplication_key_unique',
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'support_webhook_outbox',
            'support_webhook_outbox_notification_id_index',
        ))->toBeTrue();
});

it('retries a failed Reverb outbox effect without duplicating completed effects', function () {
    erinConfigureZammad();
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-OUTBOX-REVERB-RETRY',
        'subject' => 'Reverb-Ausfall nach Commit',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '834',
    ]);
    $payload = [
        'ticket' => [
            'id' => 834,
            'title' => 'Reverb-Ausfall nach Commit',
            'state' => 'open',
            'article' => [
                'id' => 9305,
                'ticket_id' => 834,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Diese Antwort wird nach dem Reverb-Ausfall erneut zugestellt.',
            ],
        ],
    ];
    $effects = Mockery::mock(SupportWebhookOutboxEffects::class);
    $effects->shouldReceive('deliver')
        ->once()
        ->withArgs(
            fn (SupportWebhookOutbox $entry): bool => $entry->effect === 'broadcast',
        )
        ->andThrow(new RuntimeException('Reverb ist vorübergehend nicht erreichbar.'));
    app()->instance(SupportWebhookOutboxEffects::class, $effects);

    $this->withoutExceptionHandling();
    expect(fn () => erinZammadWebhook(
        $this,
        $payload,
        'delivery-outbox-reverb-first',
    ))->toThrow(RuntimeException::class, 'Reverb');

    $broadcastEntry = SupportWebhookOutbox::query()
        ->where('effect', 'broadcast')
        ->sole();
    expect($broadcastEntry)
        ->processed_at->toBeNull()
        ->attempts->toBe(1)
        ->last_error->toBe('Der Support-Outbox-Effekt wartet auf einen erneuten Zustellversuch.')
        ->and(SupportWebhookOutbox::query()->count())->toBe(5)
        ->and(IntegrationReceipt::query()->sole()->status)->toBe('processed');
    Event::assertNotDispatched(SupportTicketMessageCreated::class);
    Notification::assertNothingSent();

    app()->forgetInstance(SupportWebhookOutboxEffects::class);
    $this->travel(301)->seconds();
    erinZammadWebhook($this, $payload, 'delivery-outbox-reverb-replay')
        ->assertOk();
    erinZammadWebhook($this, $payload, 'delivery-outbox-reverb-third')
        ->assertOk();

    expect(SupportWebhookOutbox::query()->count())->toBe(5)
        ->and(SupportWebhookOutbox::query()->whereNotNull('processed_at')->count())->toBe(5)
        ->and(SupportWebhookOutbox::query()->where('effect', 'broadcast')->sole()->attempts)
        ->toBe(2);
    Event::assertDispatchedTimes(SupportTicketMessageCreated::class, 1);
    Notification::assertSentToTimes($requester, ActivityNotification::class, 3);
});

it('finishes attachment import before acknowledging the outbox and recovers from a lost acknowledgement', function () {
    erinConfigureZammad();
    Storage::fake('private');
    Event::fake([SupportTicketMessageCreated::class]);
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-OUTBOX-ATTACHMENT',
        'subject' => 'Synchroner Anhangimport',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '835',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '9306',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'delivered_at' => now(),
        'body' => 'Antwort mit vollständig importiertem Anhang.',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '7306',
        'original_name' => 'sicher.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($pdf),
        'scan_result' => 'pending',
    ]);
    app(SupportWebhookOutboxRecorder::class)->record(
        $message,
        [$attachment->getKey()],
        false,
    );
    $entry = SupportWebhookOutbox::query()
        ->where('effect', 'attachment_import')
        ->sole();
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('downloadAttachment')
        ->once()
        ->withArgs(
            function (
                string $ticketId,
                string $articleId,
                string $attachmentId,
                mixed $destination,
                int $maxBytes,
            ) use ($pdf): bool {
                expect($ticketId)->toBe('835')
                    ->and($articleId)->toBe('9306')
                    ->and($attachmentId)->toBe('7306')
                    ->and($maxBytes)->toBeGreaterThan(strlen($pdf));
                fwrite($destination, $pdf);

                return true;
            },
        )
        ->andReturn([
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
            'checksum_sha256' => hash('sha256', $pdf),
        ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');
    app()->instance(TicketingProvider::class, $provider);
    app()->instance(ClamAvScanner::class, $scanner);
    $job = new ProcessSupportWebhookOutbox($entry->getKey());

    $job->handle(app(SupportWebhookOutboxEffects::class));

    $attachment->refresh();
    $entry->refresh();
    expect($attachment)
        ->scan_result->toBe('clean')
        ->disk->toBe('private')
        ->and($attachment->path)->not->toBeNull()
        ->and($entry->processed_at)->not->toBeNull()
        ->and($entry->attempts)->toBe(1);
    Storage::disk('private')->assertExists((string) $attachment->path);

    $entry->forceFill([
        'processed_at' => null,
        'locked_at' => null,
        'available_at' => now(),
    ])->save();
    $job->handle(app(SupportWebhookOutboxEffects::class));

    expect($entry->fresh())
        ->processed_at->not->toBeNull()
        ->attempts->toBe(2)
        ->and($attachment->fresh()->scan_result)->toBe('clean');
    Event::assertDispatchedTimes(SupportTicketMessageCreated::class, 2);
    Event::assertDispatched(
        SupportTicketMessageCreated::class,
        fn (SupportTicketMessageCreated $event): bool => $event->deliveryId
            === 'support-outbox-'.$entry->getKey().'-terminal',
    );
});

it('does not duplicate the in-app notification after a lost outbox acknowledgement', function () {
    $requester = User::factory()->create();
    $requester->notificationPreferences()->create([
        'event' => 'support',
        'database_enabled' => true,
        'email_enabled' => false,
        'push_enabled' => false,
        'sms_enabled' => false,
        'whatsapp_enabled' => false,
    ]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-OUTBOX-NOTIFICATION',
        'subject' => 'Idempotente Benachrichtigung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'delivered_at' => now(),
        'body' => 'Eine Benachrichtigung mit stabiler Identität.',
        'is_internal' => false,
    ]);
    app(SupportWebhookOutboxRecorder::class)->record(
        $message,
        [],
        true,
    );
    $entry = SupportWebhookOutbox::query()
        ->where('effect', 'notification_database')
        ->sole();
    $job = new ProcessSupportWebhookOutbox($entry->getKey());

    $job->handle(app(SupportWebhookOutboxEffects::class));

    expect($requester->notifications()->count())->toBe(1)
        ->and($requester->notifications()->sole()->getKey())
        ->toBe($entry->notification_id)
        ->and($entry->fresh()->processed_at)->not->toBeNull();

    $entry->refresh();
    $entry->forceFill([
        'processed_at' => null,
        'locked_at' => null,
        'available_at' => now(),
    ])->save();
    $job->handle(app(SupportWebhookOutboxEffects::class));

    expect($requester->notifications()->count())->toBe(1)
        ->and($entry->fresh()->attempts)->toBe(2)
        ->and($entry->fresh()->processed_at)->not->toBeNull()
        ->and(
            SupportWebhookOutbox::query()
                ->where('support_ticket_message_id', $message->getKey())
                ->whereNotNull('notification_id')
                ->distinct()
                ->pluck('notification_id')
                ->all(),
        )->toBe([$entry->notification_id]);
});

it('does not correlate a guessable or forged customer message marker', function () {
    erinConfigureZammad();
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-FORGED-MARKER',
        'subject' => 'Gefälschter Marker',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '826',
    ]);
    $outboundMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Noch nicht zugestellte Originalnachricht.',
        'is_internal' => false,
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 826,
            'title' => $ticket->subject,
            'state' => 'open',
            'article' => [
                'id' => 9205,
                'ticket_id' => 826,
                'subject' => sprintf(
                    'Erin operation message:%d:%s',
                    $outboundMessage->getKey(),
                    str_repeat('0', 64),
                ),
                'sender' => 'Customer',
                'internal' => false,
                'body' => 'Kundentext mit einem gefälschten Marker.',
            ],
        ],
    ], 'delivery-forged-marker')->assertOk();

    $importedMessage = $ticket->messages()
        ->where('source', 'zammad')
        ->sole();

    expect($outboundMessage->fresh())
        ->external_article_id->toBeNull()
        ->delivery_status->toBe('pending')
        ->and($importedMessage)
        ->external_article_id->toBe('9205')
        ->body->toBe('Kundentext mit einem gefälschten Marker.')
        ->and(SupportTicketMessage::query()->count())->toBe(2);
    Notification::assertNothingSent();
});

it('supports explicit marker-secret rotation and fails correlation closed after the grace window', function () {
    erinConfigureZammad();
    $oldSecret = 'zammad-old-message-marker-secret-0001';
    $newSecret = 'zammad-new-message-marker-secret-0002';
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-MARKER-ROTATION',
        'subject' => 'Markerrotation',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Während der Rotation versendet.',
        'is_internal' => false,
    ]);
    config()->set('services.zammad.message_marker_secret', $oldSecret);
    $oldMarker = app(ZammadMessageMarker::class)->for($message);

    config()->set('services.zammad.message_marker_secret', $newSecret);
    config()->set('services.zammad.previous_message_marker_secrets', [$oldSecret]);
    expect(app(ZammadMessageMarker::class)->messageId($oldMarker, $ticket->getKey()))
        ->toBe($message->getKey())
        ->and(app(ZammadMessageMarker::class)->verificationMarkersFor($message))
        ->toContain($oldMarker);

    config()->set('services.zammad.previous_message_marker_secrets', []);
    expect(app(ZammadMessageMarker::class)->messageId($oldMarker, $ticket->getKey()))
        ->toBeNull();
});

it('correlates a synchronous customer echo before the sender job stores its article ID', function () {
    erinConfigureZammad();
    Bus::fake([ImportZammadAttachment::class]);
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-ECHO-RACE',
        'subject' => 'Echo während der Zustellung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '821',
        'sync_status' => 'synced',
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Diese Nachricht darf durch das Echo nicht dupliziert werden.',
        'is_internal' => false,
    ]);
    $localAttachment = $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => 'support-tickets/echo-race.pdf',
        'original_name' => 'echo-race.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 14,
        'checksum_sha256' => hash('sha256', 'echo-race-pdf'),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->twice()->andReturnTrue();
    $provider->shouldReceive('createMessage')
        ->once()
        ->withArgs(
            fn (SupportTicket $sentTicket, SupportTicketMessage $sentMessage): bool => (
                $sentTicket->is($ticket) && $sentMessage->is($message)
            ),
        )
        ->andReturnUsing(function () use ($ticket, $message): array {
            erinZammadWebhook($this, [
                'ticket' => [
                    'id' => (int) $ticket->external_id,
                    'title' => $ticket->subject,
                    'state' => 'open',
                    'article' => [
                        'id' => 9201,
                        'ticket_id' => (int) $ticket->external_id,
                        'subject' => app(ZammadMessageMarker::class)->for($message),
                        'sender' => 'Customer',
                        'internal' => false,
                        'body' => $message->body,
                        'attachments' => [[
                            'id' => 7201,
                            'filename' => 'echo-race.pdf',
                            'size' => 14,
                        ]],
                    ],
                ],
            ], 'delivery-echo-race')->assertOk();

            return ['external_article_id' => '9201'];
        });
    app()->instance(TicketingProvider::class, $provider);

    (new SyncSupportMessageToProvider($message->getKey()))->handle($provider);

    expect(SupportTicketMessage::query()->count())->toBe(1)
        ->and($message->fresh())
        ->source->toBe('erin')
        ->external_article_id->toBe('9201')
        ->delivery_status->toBe('delivered')
        ->and($message->fresh()?->delivered_at)->not->toBeNull()
        ->and(SupportTicketAttachment::query()->count())->toBe(1)
        ->and($localAttachment->fresh())
        ->source->toBe('erin')
        ->external_id->toBeNull()
        ->scan_result->toBe('clean')
        ->and(ActivityEntry::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->sole()->status)->toBe('processed');
    Bus::assertNotDispatched(ImportZammadAttachment::class);
    Event::assertNotDispatched(SupportTicketMessageCreated::class);
    Notification::assertNothingSent();
});

it('keeps a webhook-confirmed message delivered when the outbound request times out afterwards', function () {
    erinConfigureZammad();
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-ECHO-TIMEOUT',
        'subject' => 'Bestätigung vor Timeout',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '832',
        'sync_status' => 'synced',
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Zammad bestätigt diese Nachricht noch vor dem Timeout.',
        'is_internal' => false,
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->twice()->andReturnTrue();
    $provider->shouldReceive('createMessage')
        ->once()
        ->andReturnUsing(function () use ($ticket, $message): never {
            erinZammadWebhook($this, [
                'ticket' => [
                    'id' => 832,
                    'title' => $ticket->subject,
                    'state' => 'open',
                    'article' => [
                        'id' => 9303,
                        'ticket_id' => 832,
                        'subject' => app(ZammadMessageMarker::class)->for($message),
                        'sender' => 'Customer',
                        'internal' => false,
                        'body' => $message->body,
                    ],
                ],
            ], 'delivery-echo-before-timeout')->assertOk();

            throw new RuntimeException('Simulierter Timeout nach Provider-Annahme.');
        });
    app()->instance(TicketingProvider::class, $provider);
    $job = new SyncSupportMessageToProvider($message->getKey());

    expect(fn () => $job->handle($provider))->toThrow(
        RuntimeException::class,
        'Simulierter Timeout',
    );
    $job->failed(new RuntimeException('Der Queue-Versuch ist endgültig fehlgeschlagen.'));

    expect($message->fresh())
        ->external_article_id->toBe('9303')
        ->delivery_status->toBe('delivered')
        ->and($message->fresh()?->delivered_at)->not->toBeNull()
        ->and(SupportTicketMessage::query()->count())->toBe(1);
});

it('does not swallow an agent reply that repeats an Erin message marker', function () {
    erinConfigureZammad();
    Bus::fake([ImportZammadAttachment::class]);
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-AGENT-MARKER',
        'subject' => 'Agentenantwort mit Marker',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '822',
        'sync_status' => 'synced',
    ]);
    $outboundMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Meine ursprüngliche Nachricht.',
        'is_internal' => false,
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 822,
            'title' => $ticket->subject,
            'state' => 'open',
            'article' => [
                'id' => 9202,
                'ticket_id' => 822,
                'subject' => app(ZammadMessageMarker::class)->for($outboundMessage),
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Dies ist eine echte Antwort des Support-Teams.',
                'attachments' => [[
                    'id' => 7202,
                    'filename' => 'support-antwort.pdf',
                    'size' => 128,
                ]],
            ],
        ],
    ], 'delivery-agent-marker')->assertOk();

    $agentMessage = SupportTicketMessage::query()
        ->where('source', 'zammad')
        ->sole();
    $attachment = SupportTicketAttachment::query()->sole();

    expect(SupportTicketMessage::query()->count())->toBe(2)
        ->and($outboundMessage->fresh())
        ->external_article_id->toBeNull()
        ->delivery_status->toBe('pending')
        ->and($agentMessage)
        ->external_article_id->toBe('9202')
        ->body->toBe('Dies ist eine echte Antwort des Support-Teams.')
        ->and($attachment)
        ->support_ticket_message_id->toBe($agentMessage->getKey())
        ->external_id->toBe('7202')
        ->scan_result->toBe('pending')
        ->and(ActivityEntry::query()->where('event', 'support.message_received')->count())->toBe(1);
    Bus::assertDispatched(
        ImportZammadAttachment::class,
        fn (ImportZammadAttachment $job): bool => $job->attachmentId === $attachment->getKey(),
    );
    Event::assertDispatchedTimes(SupportTicketMessageCreated::class, 1);
    Notification::assertSentToTimes($requester, ActivityNotification::class, 3);
});

it('never correlates a customer echo marker across support-ticket boundaries', function () {
    erinConfigureZammad();
    Notification::fake();
    $firstRequester = User::factory()->create();
    $secondRequester = User::factory()->create();
    $firstTicket = SupportTicket::query()->create([
        'requester_id' => $firstRequester->getKey(),
        'number' => 'ERIN-260718-ECHO-TENANT-A',
        'subject' => 'Erstes Ticket',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '823',
    ]);
    $secondTicket = SupportTicket::query()->create([
        'requester_id' => $secondRequester->getKey(),
        'number' => 'ERIN-260718-ECHO-TENANT-B',
        'subject' => 'Zweites Ticket',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '824',
    ]);
    $foreignMessage = $secondTicket->messages()->create([
        'author_id' => $secondRequester->getKey(),
        'body' => 'Nachricht des zweiten Tickets.',
        'is_internal' => false,
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 823,
            'title' => $firstTicket->subject,
            'state' => 'open',
            'article' => [
                'id' => 9203,
                'ticket_id' => 823,
                'subject' => app(ZammadMessageMarker::class)->for($foreignMessage),
                'sender' => 'Customer',
                'internal' => false,
                'body' => 'Neue Kundennachricht im ersten Ticket.',
            ],
        ],
    ], 'delivery-cross-ticket-marker')->assertOk();

    $importedMessage = $firstTicket->messages()->sole();

    expect($foreignMessage->fresh())
        ->external_article_id->toBeNull()
        ->delivery_status->toBe('pending')
        ->and($importedMessage)
        ->support_ticket_id->toBe($firstTicket->getKey())
        ->source->toBe('zammad')
        ->external_article_id->toBe('9203')
        ->body->toBe('Neue Kundennachricht im ersten Ticket.');
    Notification::assertNothingSent();
});

it('rejects a Zammad article that belongs to a different external ticket', function () {
    erinConfigureZammad();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-TENANT-A',
        'subject' => 'Unverändert',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '812',
    ]);
    SupportTicket::query()->create([
        'requester_id' => User::factory()->create()->getKey(),
        'number' => 'ERIN-260718-TENANT-B',
        'subject' => 'Anderes Ticket',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '813',
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 812,
            'title' => 'Manipulierter Betreff',
            'article' => [
                'id' => 9102,
                'ticket_id' => 813,
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Darf keinem fremden Ticket zugeordnet werden.',
            ],
        ],
    ], 'delivery-cross-ticket')->assertUnprocessable();

    expect($ticket->fresh()->subject)->toBe('Unverändert')
        ->and(SupportTicketMessage::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->count())->toBe(0);
});

it('keeps state unchanged when Zammad is unavailable while resolving an article', function () {
    erinConfigureZammad();
    Http::fakeSequence()
        ->push(['error' => 'temporarily unavailable'], 503)
        ->push(['error' => 'temporarily unavailable'], 503)
        ->push(['error' => 'temporarily unavailable'], 503);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-PROVIDER-DOWN',
        'subject' => 'Vor Provider-Ausfall',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '814',
    ]);
    $payload = [
        'ticket' => [
            'id' => 814,
            'title' => 'Darf nicht vorzeitig übernommen werden',
            'article_ids' => [9103],
        ],
    ];

    $this->withoutExceptionHandling();
    expect(fn () => erinZammadWebhook($this, $payload, 'delivery-provider-down'))
        ->toThrow(RequestException::class);

    expect($ticket->fresh()->subject)->toBe('Vor Provider-Ausfall')
        ->and(SupportTicketMessage::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->count())->toBe(0);
    Http::assertSentCount(3);
});

it('does not expose internal notes and does not notify for customer-originated articles', function () {
    erinConfigureZammad();
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-INTERNAL',
        'subject' => 'Interne Notiz',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '815',
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 815,
            'title' => 'Status intern aktualisiert',
            'state' => 'pending reminder',
            'article' => [
                'id' => 9104,
                'ticket_id' => 815,
                'sender' => 'Agent',
                'internal' => true,
                'body' => 'Interne Diagnose mit vertraulichen Details.',
            ],
        ],
    ], 'delivery-internal')->assertOk();

    expect($ticket->fresh())
        ->subject->toBe('Status intern aktualisiert')
        ->status->toBe(SupportTicketStatus::InProgress)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
    Notification::assertNothingSent();

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 815,
            'title' => 'Status intern aktualisiert',
            'state' => 'open',
            'updated_at' => '2026-07-18T12:01:00.000Z',
            'article' => [
                'id' => 9105,
                'ticket_id' => 815,
                'sender' => 'Customer',
                'internal' => false,
                'body' => 'Ergänzung durch den Kunden.',
            ],
        ],
    ], 'delivery-customer')->assertOk();

    expect(SupportTicketMessage::query()->sole())
        ->author_id->toBe($requester->getKey())
        ->body->toBe('Ergänzung durch den Kunden.')
        ->and($ticket->fresh()->status)->toBe(SupportTicketStatus::Open);
    Notification::assertNothingSent();
});

it('makes repeated synchronization jobs idempotent and redacts provider failures', function () {
    erinConfigureZammad();
    Http::fake([
        'https://zammad.example.test/api/v1/tickets' => Http::response([
            'id' => 916,
            'number' => '99126',
            'article_ids' => [9961],
        ], 201),
        'https://zammad.example.test/api/v1/ticket_articles' => Http::response([
            'id' => 9962,
        ], 201),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-IDEMPOTENT-JOBS',
        'subject' => 'Idempotente Jobs',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $firstMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Eröffnung',
        'is_internal' => false,
    ]);
    $provider = new ZammadTicketingProvider;
    $ticketJob = new SyncSupportTicketToProvider($ticket->getKey());

    $ticketJob->handle($provider);
    $ticketJob->handle($provider);
    $reply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Antwort',
        'is_internal' => false,
    ]);
    $messageJob = new SyncSupportMessageToProvider($reply->getKey());
    $messageJob->handle($provider);
    $messageJob->handle($provider);

    expect(Http::recorded(
        fn (ClientRequest $request): bool => $request->method() === 'POST',
    ))->toHaveCount(2)
        ->and($firstMessage->fresh()->external_article_id)->toBe('9961')
        ->and($reply->fresh()->external_article_id)->toBe('9962');

    $failedTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-REDACTED-FAILURE',
        'subject' => 'Fehlertext',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    (new SyncSupportTicketToProvider($failedTicket->getKey()))
        ->failed(new RuntimeException('remote token=top-secret'));

    expect($failedTicket->fresh())
        ->sync_status->toBe('failed')
        ->sync_error->toBe('Die Synchronisierung mit dem Ticketsystem ist nach mehreren Versuchen fehlgeschlagen.')
        ->sync_error->not->toContain('top-secret');
});

it('provides a tenant-safe live support conversation without exposing internal notes', function () {
    Bus::fake();
    Event::fake([SupportTicketMessageCreated::class]);
    ['user' => $owner, 'company' => $company] = erinSupportEmployer();
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('support.tickets.store'), [
            'subject' => 'Import hängt',
            'category' => 'candidate_import',
            'priority' => 'high',
            'message' => 'Der CSV-Import bleibt bei der Verarbeitung stehen.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $ticket = SupportTicket::query()->sole();
    $firstMessage = $ticket->messages()->sole();

    expect($ticket)
        ->requester_id->toBe($owner->getKey())
        ->company_id->toBe($company->getKey())
        ->status->toBe(SupportTicketStatus::Open)
        ->and($firstMessage->body)
        ->toBe('Der CSV-Import bleibt bei der Verarbeitung stehen.');
    Bus::assertDispatched(
        SyncSupportTicketToProvider::class,
        fn (SyncSupportTicketToProvider $job): bool => $job->ticketId === $ticket->getKey(),
    );

    $ticket->update([
        'external_system' => 'zammad',
        'external_id' => '990',
        'sync_status' => 'synced',
    ]);
    $ticket->messages()->create([
        'author_id' => null,
        'body' => 'Interne Diagnose mit Zugangsdaten.',
        'is_internal' => true,
        'source' => 'zammad',
        'delivery_status' => 'delivered',
    ]);

    $this->actingAs($stranger)
        ->post(route('support.tickets.reply', $ticket), [
            'message' => 'Unzulässige Antwort',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('support.tickets.reply', $ticket), [
            'message' => 'Hier sind weitere Details.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $reply = $ticket->messages()
        ->where('is_internal', false)
        ->latest('id')
        ->firstOrFail();
    Bus::assertDispatched(
        SyncSupportMessageToProvider::class,
        fn (SyncSupportMessageToProvider $job): bool => $job->messageId === $reply->getKey(),
    );
    Event::assertDispatchedTimes(SupportTicketMessageCreated::class, 2);
    Event::assertDispatched(
        SupportTicketMessageCreated::class,
        function (SupportTicketMessageCreated $event) use ($ticket): bool {
            return $event->message->support_ticket_id === $ticket->getKey()
                && $event->broadcastAs() === 'support.message.created'
                && $event->broadcastWhen()
                && $event->broadcastOn()->name === 'private-support-ticket.'.$ticket->getKey();
        },
    );

    config()->set('support.attachments.max_files', 3);
    config()->set('support.attachments.max_kilobytes', 2048);
    config()->set('support.attachments.max_total_kilobytes', 5120);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('support.index', ['ticket' => $ticket->getKey()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Support')
            ->where('selected', $ticket->getKey())
            ->has('tickets', 1)
            ->where('tickets.0.id', $ticket->getKey())
            ->has('tickets.0.messages', 2)
            ->where('tickets.0.messages.0.body', $firstMessage->body)
            ->where('tickets.0.messages.1.body', 'Hier sind weitere Details.')
            ->where('attachmentLimits.maxFiles', 3)
            ->where('attachmentLimits.maxFileMegabytes', 2)
            ->where('attachmentLimits.maxTotalMegabytes', 5));
});

it('scopes company support tickets to the accepted active membership', function () {
    Bus::fake();
    Event::fake([SupportTicketMessageCreated::class]);
    ['user' => $owner, 'company' => $firstCompany] = erinSupportEmployer();
    $secondCompany = Company::factory()->create();
    $pendingCompany = Company::factory()->create();

    CompanyMembership::query()->create([
        'company_id' => $secondCompany->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Recruiter,
        'accepted_at' => now(),
    ]);
    CompanyMembership::query()->create([
        'company_id' => $pendingCompany->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Recruiter,
        'accepted_at' => null,
    ]);

    $firstTicket = SupportTicket::query()->create([
        'requester_id' => $owner->getKey(),
        'company_id' => $firstCompany->getKey(),
        'number' => 'ERIN-260718-FIRST',
        'subject' => 'Ticket der ersten Firma',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now()->subMinute(),
    ]);
    $secondTicket = SupportTicket::query()->create([
        'requester_id' => $owner->getKey(),
        'company_id' => $secondCompany->getKey(),
        'number' => 'ERIN-260718-SECOND',
        'subject' => 'Ticket der aktiven Firma',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now(),
    ]);
    SupportTicket::query()->create([
        'requester_id' => $owner->getKey(),
        'company_id' => $pendingCompany->getKey(),
        'number' => 'ERIN-260718-PENDING',
        'subject' => 'Ticket der ausstehenden Firma',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now()->addMinute(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $secondCompany->getKey()])
        ->get(route('support.index', ['ticket' => $firstTicket->getKey()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Support')
            ->where('selected', $secondTicket->getKey())
            ->has('tickets', 1)
            ->where('tickets.0.id', $secondTicket->getKey()));

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $secondCompany->getKey()])
        ->post(route('support.tickets.store'), [
            'subject' => 'Neues Ticket der aktiven Firma',
            'priority' => 'high',
            'message' => 'Dieses Ticket muss der zweiten Firma zugeordnet werden.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(SupportTicket::query()
        ->where('subject', 'Neues Ticket der aktiven Firma')
        ->sole()
        ->company_id)->toBe($secondCompany->getKey());

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $secondCompany->getKey()])
        ->post(route('support.tickets.reply', $firstTicket), [
            'message' => 'Diese Antwort darf den Mandanten nicht wechseln.',
        ])
        ->assertNotFound();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $pendingCompany->getKey()])
        ->get(route('support.index'))
        ->assertForbidden();
});

it('correlates a synchronous staff echo only with an Agent article of identical content', function () {
    erinConfigureZammad();
    Event::fake([SupportTicketMessageCreated::class]);
    Notification::fake();
    $requester = User::factory()->create();
    $staff = User::factory()->create(['role' => UserRole::Support]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-STAFF-ECHO',
        'subject' => 'Supportantwort',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '940',
        'sync_status' => 'synced',
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $staff->getKey(),
        'body' => 'Wir haben deinen Fall geprüft.',
        'is_internal' => false,
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->twice()->andReturnTrue();
    $provider->shouldReceive('createMessage')
        ->once()
        ->andReturnUsing(function () use ($ticket, $message): array {
            erinZammadWebhook($this, [
                'ticket' => [
                    'id' => 940,
                    'title' => $ticket->subject,
                    'state' => 'open',
                    'article' => [
                        'id' => 9401,
                        'ticket_id' => 940,
                        'subject' => app(ZammadMessageMarker::class)->for($message),
                        'sender' => 'Agent',
                        'internal' => false,
                        'body' => '<p>Wir haben deinen Fall geprüft.</p>',
                    ],
                ],
            ], 'delivery-staff-echo')->assertOk();

            return ['external_article_id' => '9401'];
        });
    app()->instance(TicketingProvider::class, $provider);

    (new SyncSupportMessageToProvider($message->getKey()))->handle($provider);

    expect(SupportTicketMessage::query()->count())->toBe(1)
        ->and($message->fresh())
        ->external_article_id->toBe('9401')
        ->delivery_status->toBe('delivered')
        ->and(SupportZammadArticleReceipt::query()->sole())
        ->external_article_id->toBe('9401')
        ->is_internal->toBeFalse()
        ->and(SupportWebhookOutbox::query()->count())->toBe(0)
        ->and(ActivityEntry::query()->count())->toBe(0);
    Event::assertNotDispatched(SupportTicketMessageCreated::class);
    Notification::assertNothingSent();
});

it('imports a foreign Agent response that copies a staff marker but changes the content', function () {
    erinConfigureZammad();
    Bus::fake([ProcessSupportWebhookOutbox::class]);
    Notification::fake();
    $requester = User::factory()->create();
    $staff = User::factory()->create(['role' => UserRole::Support]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-FOREIGN-AGENT-ECHO',
        'subject' => 'Fremde Agentenantwort',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '941',
    ]);
    $outbound = $ticket->messages()->create([
        'author_id' => $staff->getKey(),
        'body' => 'Ursprüngliche Erin-Antwort.',
        'is_internal' => false,
    ]);

    erinZammadWebhook($this, [
        'ticket' => [
            'id' => 941,
            'title' => $ticket->subject,
            'state' => 'open',
            'article' => [
                'id' => 9411,
                'ticket_id' => 941,
                'subject' => app(ZammadMessageMarker::class)->for($outbound),
                'sender' => 'Agent',
                'internal' => false,
                'body' => 'Eine andere Antwort mit kopiertem Betreff.',
            ],
        ],
    ], 'delivery-foreign-agent-echo')->assertOk();

    $imported = $ticket->messages()->where('source', 'zammad')->sole();
    expect($outbound->fresh())
        ->external_article_id->toBeNull()
        ->delivery_status->toBe('pending')
        ->and($imported)
        ->external_article_id->toBe('9411')
        ->body->toBe('Eine andere Antwort mit kopiertem Betreff.')
        ->and(SupportTicketMessage::query()->count())->toBe(2)
        ->and(SupportWebhookOutbox::query()->count())->toBe(5);
});

it('persists the reconciliation deadline and requires every configured miss before another POST', function () {
    erinConfigureZammad();
    config()->set('services.zammad.reconcile_required_misses', 3);
    Bus::fake([SyncSupportMessageToProvider::class, SyncSupportTicketToProvider::class]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-RECONCILE-GRACE',
        'subject' => 'Persistierte Karenzzeit',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '942',
        'sync_status' => 'synced',
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Nicht vorschnell erneut senden.',
        'is_internal' => false,
        'delivery_status' => 'sending',
        'external_reconcile_not_before' => now()->addSeconds(10),
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->times(4)->andReturnTrue();
    $provider->shouldReceive('findMessage')->times(3)->andReturnNull();
    $provider->shouldReceive('createMessage')
        ->once()
        ->andReturn(['external_article_id' => '9421']);
    $earlyJob = (new SyncSupportMessageToProvider($message->getKey()))
        ->withFakeQueueInteractions();

    $earlyJob->handle($provider);
    $earlyJob->assertReleased(10);
    expect($message->fresh())
        ->external_reconcile_attempts->toBe(0)
        ->external_article_id->toBeNull();

    $this->travel(11)->seconds();
    foreach ([1, 2] as $expectedMisses) {
        $job = (new SyncSupportMessageToProvider($message->getKey()))
            ->withFakeQueueInteractions();
        $job->handle($provider);
        $job->assertReleased(1);
        expect($message->fresh())
            ->external_reconcile_attempts->toBe($expectedMisses)
            ->external_article_id->toBeNull();
        $this->travel(2)->seconds();
    }

    (new SyncSupportMessageToProvider($message->getKey()))->handle($provider);

    expect($message->fresh())
        ->external_article_id->toBe('9421')
        ->delivery_status->toBe('delivered')
        ->external_reconcile_attempts->toBe(0)
        ->external_reconcile_not_before->toBeNull()
        ->and($ticket->fresh()->last_synced_at)->not->toBeNull();

    $futureTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-FUTURE-TICKET',
        'subject' => 'Noch nicht fällig',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'failed',
        'external_reconcile_not_before' => now()->addMinute(),
    ]);
    $dueTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-DUE-TICKET',
        'subject' => 'Jetzt fällig',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'failed',
        'external_reconcile_not_before' => now()->subSecond(),
    ]);
    $counts = app(SupportOutboundReconciliationDispatcher::class)->dispatchDue();

    expect($counts)->toBe(['tickets' => 1, 'messages' => 0]);
    Bus::assertDispatched(
        SyncSupportTicketToProvider::class,
        fn (SyncSupportTicketToProvider $job): bool => $job->ticketId === $dueTicket->getKey(),
    );
    Bus::assertNotDispatched(
        SyncSupportTicketToProvider::class,
        fn (SyncSupportTicketToProvider $job): bool => $job->ticketId === $futureTicket->getKey(),
    );
});

it('maps a ticket and its opening article atomically and rejects incomplete provider identities', function () {
    erinConfigureZammad();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-ATOMIC-MAPPING',
        'subject' => 'Atomare Zuordnung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Eröffnungsnachricht.',
        'is_internal' => false,
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('enabled')->once()->andReturnTrue();
    $provider->shouldReceive('createTicket')->once()->andReturn([
        'external_id' => '943',
        'external_number' => '943',
        'external_article_id' => '9431',
    ]);
    $eventName = 'eloquent.updating: '.SupportTicketMessage::class;
    Event::listen($eventName, static function (SupportTicketMessage $updating): void {
        if ($updating->isDirty('external_article_id')) {
            throw new RuntimeException('Simulierter Fehler beim Speichern des Eröffnungsartikels.');
        }
    });

    try {
        expect(fn () => (new SyncSupportTicketToProvider($ticket->getKey()))
            ->handle($provider))->toThrow(RuntimeException::class, 'Simulierter Fehler');
    } finally {
        Event::forget($eventName);
    }

    expect($ticket->fresh())
        ->external_id->toBeNull()
        ->sync_status->toBe('syncing')
        ->and($message->fresh()->external_article_id)->toBeNull()
        ->and(SupportZammadArticleReceipt::query()->count())->toBe(0);

    $malformedTicket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-MALFORMED-MAPPING',
        'subject' => 'Unvollständige Zuordnung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $malformedTicket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Noch eine Eröffnungsnachricht.',
        'is_internal' => false,
    ]);
    $malformedProvider = Mockery::mock(TicketingProvider::class);
    $malformedProvider->shouldReceive('enabled')->once()->andReturnTrue();
    $malformedProvider->shouldReceive('createTicket')->once()->andReturn([
        'external_id' => '944',
        'external_number' => null,
        'external_article_id' => null,
    ]);

    expect(fn () => (new SyncSupportTicketToProvider($malformedTicket->getKey()))
        ->handle($malformedProvider))->toThrow(
            RuntimeException::class,
            'Eröffnungsartikel',
        );
    expect($malformedTicket->fresh()->external_id)->toBeNull();
});

it('verifies the exact outbox identity instead of accepting a conflicting deduplication row', function () {
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-OUTBOX-IDENTITY',
        'subject' => 'Outbox-Identität',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'body' => 'Eine importierte Nachricht.',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'is_internal' => false,
    ]);
    $recorder = app(SupportWebhookOutboxRecorder::class);

    $recorder->record($message, [], false);
    $recorder->record($message, [], false);
    expect(SupportWebhookOutbox::query()->count())->toBe(1);

    SupportWebhookOutbox::query()->sole()->update([
        'effect' => 'notification_database',
        'recipient_id' => $requester->getKey(),
    ]);

    expect(fn () => $recorder->record($message, [], false))->toThrow(
        RuntimeException::class,
        'widersprüchliche Zustelldaten',
    );
});

it('retries a transient attachment outbox failure without terminally rejecting the file', function () {
    Storage::fake('private');
    Event::fake([SupportTicketMessageCreated::class]);
    $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-OUTBOX-ATTACHMENT-RETRY',
        'subject' => 'Temporärer Attachment-Fehler',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '946',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '9461',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Antwort mit temporär nicht verfügbarem Anhang.',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '9462',
        'original_name' => 'erneut-versuchen.pdf',
        'scan_result' => 'pending',
    ]);
    app(SupportWebhookOutboxRecorder::class)->record(
        $message,
        [$attachment->getKey()],
        false,
    );
    $entry = SupportWebhookOutbox::query()
        ->where('effect', 'attachment_import')
        ->sole();
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('downloadAttachment')
        ->once()
        ->andThrow(new RuntimeException('Zammad ist vorübergehend nicht erreichbar.'));
    $provider->shouldReceive('downloadAttachment')
        ->once()
        ->andReturnUsing(function (
            string $ticketId,
            string $articleId,
            string $attachmentId,
            mixed $destination,
        ) use ($pdf): array {
            expect($ticketId)->toBe('946')
                ->and($articleId)->toBe('9461')
                ->and($attachmentId)->toBe('9462');
            fwrite($destination, $pdf);

            return [
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'checksum_sha256' => hash('sha256', $pdf),
            ];
        });
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');
    app()->instance(TicketingProvider::class, $provider);
    app()->instance(ClamAvScanner::class, $scanner);
    $job = new ProcessSupportWebhookOutbox($entry->getKey());

    expect(fn () => $job->handle(
        app(SupportWebhookOutboxEffects::class),
    ))->toThrow(RuntimeException::class, 'vorübergehend');

    expect($entry->fresh())
        ->attempts->toBe(1)
        ->processed_at->toBeNull()
        ->last_error->toBe('Der Support-Outbox-Effekt konnte noch nicht zugestellt werden.')
        ->and($attachment->fresh())
        ->scan_result->toBe('pending')
        ->path->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([]);

    $this->travel(31)->seconds();
    $job->handle(app(SupportWebhookOutboxEffects::class));

    expect($entry->fresh())
        ->attempts->toBe(2)
        ->processed_at->not->toBeNull()
        ->last_error->toBeNull()
        ->and($attachment->fresh())
        ->scan_result->toBe('clean')
        ->path->toBe(sprintf(
            'support-tickets/%d/zammad/%d.pdf',
            $ticket->getKey(),
            $attachment->getKey(),
        ));
    Storage::disk('private')->assertExists((string) $attachment->fresh()->path);
});

it('imports all unseen article IDs in provider order while recording internal articles durably', function () {
    erinConfigureZammad();
    Bus::fake([ProcessSupportWebhookOutbox::class]);
    Notification::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-ALL-ARTICLES',
        'subject' => 'Mehrere Artikel',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '945',
    ]);
    $articles = [
        9451 => [
            'id' => 9451,
            'ticket_id' => 945,
            'sender' => 'Agent',
            'internal' => false,
            'updated_at' => '2026-07-18T09:01:00.000Z',
            'body' => 'Erste öffentliche Antwort.',
        ],
        9452 => [
            'id' => 9452,
            'ticket_id' => 945,
            'sender' => 'Agent',
            'internal' => true,
            'updated_at' => '2026-07-18T09:02:00.000Z',
            'body' => 'Interne Notiz.',
        ],
        9453 => [
            'id' => 9453,
            'ticket_id' => 945,
            'sender' => 'Customer',
            'internal' => false,
            'updated_at' => '2026-07-18T09:03:00.000Z',
            'body' => 'Antwort des Kunden.',
        ],
    ];
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_articles/9451' => Http::response($articles[9451]),
        'https://zammad.example.test/api/v1/ticket_articles/9452' => Http::response($articles[9452]),
        'https://zammad.example.test/api/v1/ticket_articles/9453' => Http::response($articles[9453]),
    ]);
    $payload = [
        'ticket' => [
            'id' => 945,
            'title' => $ticket->subject,
            'state' => 'open',
            'updated_at' => '2026-07-18T09:04:00.000Z',
            'article_ids' => [9454, 9452, 9453, 9451],
            'article' => [
                'id' => 9454,
                'ticket_id' => 945,
                'sender' => 'Agent',
                'internal' => false,
                'updated_at' => '2026-07-18T09:04:00.000Z',
                'body' => 'Letzte öffentliche Antwort.',
            ],
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-all-articles')->assertOk();

    expect($ticket->messages()->orderBy('id')->pluck('external_article_id')->all())
        ->toBe(['9451', '9453', '9454'])
        ->and($ticket->messages()->pluck('body')->all())
        ->not->toContain('Interne Notiz.')
        ->and(SupportZammadArticleReceipt::query()
            ->orderBy('external_article_id')
            ->pluck('external_article_id')
            ->all())
        ->toBe(['9451', '9452', '9453', '9454'])
        ->and($ticket->fresh()->status)->toBe(SupportTicketStatus::WaitingForCustomer);
    Http::assertSentCount(3);

    erinZammadWebhook($this, $payload, 'delivery-all-articles-replay')->assertOk();

    expect($ticket->messages()->count())->toBe(3)
        ->and(SupportZammadArticleReceipt::query()->count())->toBe(4);
    Http::assertSentCount(3);
});
