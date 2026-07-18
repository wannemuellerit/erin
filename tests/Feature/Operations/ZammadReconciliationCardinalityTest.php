<?php

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Ticketing\ZammadMessageMarker;
use App\Services\Ticketing\ZammadTicketingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.env', 'testing');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.allow_local_http', false);
    config()->set('services.zammad.local_http_hosts', []);
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set(
        'services.zammad.message_marker_secret',
        'zammad-message-marker-secret-current',
    );
    config()->set('services.zammad.previous_message_marker_secrets', []);
});

it('fails closed when reconciliation finds multiple matching Zammad tickets', function () {
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-DUPLICATE-TICKETS',
        'subject' => 'Mehrdeutige Ticketzuordnung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'syncing',
    ]);
    $openingMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Diese Eröffnungsnachricht darf nur einem Ticket gehören.',
        'is_internal' => false,
        'delivery_status' => 'sending',
    ]);
    $ticketMarker = 'Erin operation ticket:'.$ticket->number;
    $messageMarker = app(ZammadMessageMarker::class)->for($openingMessage);

    Http::fake([
        'https://zammad.example.test/api/v1/tickets/search*' => Http::response([
            'assets' => [
                'Ticket' => [
                    '951' => [
                        'id' => 951,
                        'number' => '9951',
                        'note' => $ticketMarker,
                    ],
                    '952' => [
                        'id' => 952,
                        'number' => '9952',
                        'note' => $ticketMarker,
                    ],
                ],
            ],
        ]),
        'https://zammad.example.test/api/v1/tickets/951' => Http::response([
            'id' => 951,
            'number' => '9951',
        ]),
        'https://zammad.example.test/api/v1/tickets/952' => Http::response([
            'id' => 952,
            'number' => '9952',
        ]),
        'https://zammad.example.test/api/v1/ticket_articles/by_ticket/951' => Http::response([
            [
                'id' => 9511,
                'ticket_id' => 951,
                'subject' => $messageMarker,
                'body' => $openingMessage->body,
                'internal' => false,
                'sender' => 'Customer',
            ],
        ]),
        'https://zammad.example.test/api/v1/ticket_articles/by_ticket/952' => Http::response([
            [
                'id' => 9521,
                'ticket_id' => 952,
                'subject' => $messageMarker,
                'body' => $openingMessage->body,
                'internal' => false,
                'sender' => 'Customer',
            ],
        ]),
    ]);

    expect(fn () => (new ZammadTicketingProvider)->findTicket(
        $ticket,
        $openingMessage,
    ))->toThrow(RuntimeException::class, 'Mehrere Zammad-Tickets');

    Http::assertSentCount(5);
    Http::assertNotSent(
        fn (ClientRequest $request): bool => $request->method() === 'POST',
    );
});

it('fails closed when reconciliation finds multiple matching Zammad articles', function () {
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-DUPLICATE-ARTICLES',
        'subject' => 'Mehrdeutige Artikelzuordnung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '953',
        'sync_status' => 'synced',
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Diese Nachricht darf nur einem Artikel gehören.',
        'is_internal' => false,
        'delivery_status' => 'sending',
    ]);
    $messageMarker = app(ZammadMessageMarker::class)->for($message);

    Http::fake([
        'https://zammad.example.test/api/v1/ticket_articles/by_ticket/953' => Http::response([
            [
                'id' => 9531,
                'ticket_id' => 953,
                'subject' => $messageMarker,
                'body' => $message->body,
                'internal' => false,
                'sender' => 'Customer',
            ],
            [
                'id' => 9532,
                'ticket_id' => 953,
                'subject' => $messageMarker,
                'body' => $message->body,
                'internal' => false,
                'sender' => 'Customer',
            ],
        ]),
    ]);

    expect(fn () => (new ZammadTicketingProvider)->findMessage(
        $ticket,
        $message,
    ))->toThrow(RuntimeException::class, 'Mehrere Zammad-Artikel');

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (ClientRequest $request): bool => $request->method() === 'POST',
    );
});

it('fails closed when ticket details contradict the requested Zammad ticket ID', function () {
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-260718-CONTRADICTING-TICKET-ID',
        'subject' => 'Widersprüchliche Ticketdetails',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'syncing',
    ]);
    $openingMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Die Detailantwort muss zur angefragten Ticket-ID gehören.',
        'is_internal' => false,
        'delivery_status' => 'sending',
    ]);

    Http::fake([
        'https://zammad.example.test/api/v1/tickets/search*' => Http::response([
            'assets' => [
                'Ticket' => [
                    '954' => [
                        'id' => 954,
                        'number' => '9954',
                        'note' => 'Erin operation ticket:'.$ticket->number,
                    ],
                ],
            ],
        ]),
        'https://zammad.example.test/api/v1/tickets/954' => Http::response([
            'id' => 955,
            'number' => '9955',
        ]),
    ]);

    expect(fn () => (new ZammadTicketingProvider)->findTicket(
        $ticket,
        $openingMessage,
    ))->toThrow(RuntimeException::class, 'widersprechen der angefragten Ticket-ID');

    Http::assertSentCount(2);
    Http::assertNotSent(
        fn (ClientRequest $request): bool => $request->method() === 'POST',
    );
});
