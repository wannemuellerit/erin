<?php

use App\Enums\CompanyMemberRole;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Events\SupportTicketMessageCreated;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\IntegrationReceipt;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Ticketing\ZammadTicketingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(RefreshDatabase::class);

function erinConfigureZammad(): void
{
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set('services.zammad.webhook_secret', 'zammad-webhook-secret');
    config()->set('services.zammad.group', 'Erin Support');
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

    Http::assertSent(function (ClientRequest $request) use ($firstMessage): bool {
        if ($request->url() !== 'https://zammad.example.test/api/v1/tickets') {
            return false;
        }

        return $request->hasHeader('Authorization', 'Token token=zammad-test-token')
            && $request['group'] === 'Erin Support'
            && $request['customer'] !== null
            && $request['article']['subject'] === 'Erin operation message:'.$firstMessage->getKey()
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
                'subject' => 'Erin operation message:'.$firstMessage->getKey(),
                'body' => $firstMessage->body,
                'internal' => false,
            ],
            [
                'id' => 9912,
                'ticket_id' => 912,
                'subject' => 'Erin operation message:'.$reply->getKey(),
                'body' => $reply->body,
                'internal' => false,
            ],
            [
                'id' => 9999,
                'ticket_id' => 912,
                'subject' => 'Antwort des Support-Teams',
                'body' => 'Diese zwischenzeitliche Antwort darf nicht als Eröffnungsartikel gelten.',
                'internal' => false,
            ],
        ]),
    ]);
    $provider = new ZammadTicketingProvider;

    (new SyncSupportTicketToProvider($ticket->getKey()))->handle($provider);
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

    $reply = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Auch dieser fehlende Artikel darf nach dem Abgleich erneut erstellt werden.',
        'is_internal' => false,
        'delivery_status' => 'failed',
    ]);
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

    expect($ticket->fresh())
        ->subject->toBe('Aktualisierter Betreff')
        ->status->toBe(SupportTicketStatus::WaitingForCustomer)
        ->sync_status->toBe('synced')
        ->and($message)
        ->external_article_id->toBe('9001')
        ->source->toBe('zammad')
        ->delivery_status->toBe('delivered')
        ->body->toBe("Wir haben das Problem behoben.\nBitte erneut testen.")
        ->and($message->attachments)->toHaveCount(1)
        ->and($message->attachments[0]['external_id'])->toBe(44)
        ->and($message->attachments[0]['filename'])->toBe('anleitung.pdf')
        ->and($message->attachments[0]['size'])->toBe(12345)
        ->and($message->attachments[0])->not->toHaveKey('content')
        ->and(IntegrationReceipt::query()->count())->toBe(1)
        ->and(IntegrationReceipt::query()->sole()->status)->toBe('processed');

    Notification::assertSentToTimes($requester, ActivityNotification::class, 1);
});

it('rejects a reused Zammad delivery ID when the payload differs', function () {
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
            'article' => [
                'id' => 9002,
                'sender' => 'Agent',
                'internal' => true,
                'body' => 'Interne Notiz',
            ],
        ],
    ];

    erinZammadWebhook($this, $payload, 'delivery-reused')->assertOk();
    $payload['ticket']['title'] = 'Manipulierte zweite Version';

    $this->withoutExceptionHandling();
    expect(fn () => erinZammadWebhook($this, $payload, 'delivery-reused'))
        ->toThrow(
            RuntimeException::class,
            'Integration event ID was reused with a different payload.',
        );

    expect(IntegrationReceipt::query()->count())->toBe(1)
        ->and(SupportTicketMessage::query()->count())->toBe(0);
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
            ->where('tickets.0.messages.1.body', 'Hier sind weitere Details.'));
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
