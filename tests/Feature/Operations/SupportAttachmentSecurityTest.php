<?php

use App\Contracts\TicketingProvider;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Exceptions\SupportAttachmentIntegrityException;
use App\Exceptions\SupportAttachmentLimitExceeded;
use App\Jobs\ImportZammadAttachment;
use App\Jobs\ScanSupportTicketAttachment;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use App\Services\Documents\ClamAvScanner;
use App\Services\Ticketing\SupportAttachmentLimits;
use App\Services\Ticketing\SupportAttachmentManager;
use App\Services\Ticketing\SupportAttachmentOrphanCleaner;
use App\Services\Ticketing\SupportSyncLock;
use App\Services\Ticketing\SupportTicketMessagePresenter;
use App\Services\Ticketing\ZammadTicketingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    Queue::fake();
});

it('stores support uploads privately, scans them and authorizes signed downloads', function () {
    $requester = User::factory()->create([
        'role' => UserRole::Candidate,
        'email_verified_at' => now(),
    ]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-01',
        'subject' => 'Anhang prüfen',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now(),
    ]);
    $file = UploadedFile::fake()->createWithContent(
        'nachweis.pdf',
        "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
    );

    $this->actingAs($requester)
        ->post(route('support.tickets.reply', $ticket), [
            'message' => '',
            'attachments' => [$file],
        ])
        ->assertRedirect();

    $attachment = SupportTicketAttachment::query()->sole();
    expect($attachment)
        ->source->toBe('erin')
        ->scan_result->toBe('pending')
        ->disk->toBe('private')
        ->path->not->toBeNull()
        ->and($attachment->path)->not->toContain($attachment->original_name);
    Storage::disk('private')->assertExists((string) $attachment->path);
    Queue::assertPushed(
        ScanSupportTicketAttachment::class,
        fn (ScanSupportTicketAttachment $job): bool => $job->attachmentId === $attachment->getKey(),
    );

    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');
    (new ScanSupportTicketAttachment($attachment->getKey()))->handle($scanner);

    $attachment->refresh();
    $message = $attachment->message()->firstOrFail();
    $presented = app(SupportTicketMessagePresenter::class)->present($message);
    $downloadUrl = $presented['attachments'][0]['download_url'] ?? null;

    expect($attachment)
        ->scan_result->toBe('clean')
        ->checksum_sha256->not->toBeNull()
        ->and($downloadUrl)->toBeString();

    $this->actingAs($requester)
        ->get((string) $downloadUrl)
        ->assertOk()
        ->assertHeader('content-disposition')
        ->assertStreamedContent("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($otherUser)
        ->get((string) $downloadUrl)
        ->assertForbidden();
});

it('deletes infected support uploads and never creates a download link', function () {
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-02',
        'subject' => 'Malware blockieren',
        'priority' => 'high',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Bitte prüfen.',
        'is_internal' => false,
    ]);
    Storage::disk('private')->put('support-tickets/malware.pdf', 'malware');
    $attachment = $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => 'support-tickets/malware.pdf',
        'original_name' => 'malware.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 7,
        'checksum_sha256' => hash('sha256', 'malware'),
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('infected');

    (new ScanSupportTicketAttachment($attachment->getKey()))->handle($scanner);

    $attachment->refresh();
    $presented = app(SupportTicketMessagePresenter::class)->present($message);
    expect($attachment->scan_result)->toBe('infected')
        ->and($message->fresh()->delivery_status)->toBe('failed')
        ->and($presented['attachments'][0]['download_url'])->toBeNull();
    Storage::disk('private')->assertMissing('support-tickets/malware.pdf');
});

it('imports Zammad attachments into private storage before exposing them', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_attachment/701/801/901' => Http::response(
            $pdf,
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-03',
        'subject' => 'Zammad-Anhang',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '701',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '801',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Antwort aus Zammad',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '901',
        'original_name' => 'antwort.pdf',
        'size_bytes' => strlen($pdf),
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');

    (new ImportZammadAttachment($attachment->getKey()))
        ->handle(
            new ZammadTicketingProvider,
            $scanner,
            app(SupportAttachmentLimits::class),
        );

    $attachment->refresh();
    expect($attachment)
        ->disk->toBe('private')
        ->path->toBe(sprintf(
            'support-tickets/%d/zammad/%d.pdf',
            $ticket->getKey(),
            $attachment->getKey(),
        ))
        ->mime_type->toBe('application/pdf')
        ->size_bytes->toBe(strlen($pdf))
        ->scan_result->toBe('clean')
        ->checksum_sha256->toBe(hash('sha256', $pdf));
    Storage::disk('private')->assertExists((string) $attachment->path);
    Http::assertSent(
        fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://zammad.example.test/api/v1/ticket_attachment/701/801/901'
            && $request->hasHeader('Authorization', 'Token token=zammad-test-token'),
    );
});

it('sends only clean, unchanged private attachments to Zammad as base64', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    Http::fake([
        'https://zammad.example.test/api/v1/tickets' => Http::response([
            'id' => 501,
            'number' => '99001',
            'article_ids' => [8001],
        ], 201),
    ]);
    $contents = "%PDF-1.4\n%%EOF";
    Storage::disk('private')->put('support-tickets/clean.pdf', $contents);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-04',
        'subject' => 'Ausgehender Anhang',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Dokument im Anhang.',
        'is_internal' => false,
    ]);
    $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => 'support-tickets/clean.pdf',
        'original_name' => 'clean.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($contents),
        'checksum_sha256' => hash('sha256', $contents),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);

    (new ZammadTicketingProvider)->createTicket($ticket, $message);

    Http::assertSent(function (ClientRequest $request) use ($contents): bool {
        $article = $request->data()['article'] ?? [];
        $attachment = $article['attachments'][0] ?? [];

        return $request->method() === 'POST'
            && $attachment['filename'] === 'clean.pdf'
            && $attachment['mime-type'] === 'application/pdf'
            && $attachment['data'] === base64_encode($contents);
    });
});

it('rejects individual and aggregate support upload limits before creating messages', function () {
    config()->set('support.attachments.max_kilobytes', 10240);
    config()->set('support.attachments.max_total_kilobytes', 15360);
    $requester = User::factory()->create([
        'email_verified_at' => now(),
        'locale' => 'en',
    ]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-05',
        'subject' => 'Dateigrenzen',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);

    $this->actingAs($requester)
        ->post(route('support.tickets.reply', $ticket), [
            'message' => '',
            'attachments' => [
                UploadedFile::fake()->create('zu-gross.pdf', 10241, 'application/pdf'),
            ],
        ])
        ->assertSessionHasErrors('attachments.0');

    $response = $this->actingAs($requester)
        ->post(route('support.tickets.reply', $ticket), [
            'message' => '',
            'attachments' => [
                UploadedFile::fake()->create('teil-1.pdf', 8192, 'application/pdf'),
                UploadedFile::fake()->create('teil-2.pdf', 8192, 'application/pdf'),
            ],
        ]);

    $response->assertSessionHasErrors([
        'attachments' => trans(
            'validation.support_attachments_total',
            ['size' => 15],
            'en',
        ),
    ]);

    expect(trans(
        'validation.support_attachments_total',
        ['size' => 15],
        'de',
    ))->toBe('Die Anhänge dürfen zusammen höchstens 15 MB groß sein.')
        ->and($ticket->messages()->count())->toBe(0);
});

it('rolls back user replies when storing an attachment fails', function () {
    $requester = User::factory()->create(['email_verified_at' => now()]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-06',
        'subject' => 'Transaktion Nutzerantwort',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now()->subDay(),
    ]);
    $originalLastReplyAt = $ticket->last_reply_at;
    $manager = Mockery::mock(SupportAttachmentManager::class);
    $manager->shouldReceive('storeUploads')
        ->once()
        ->andThrow(new RuntimeException('Speicher nicht verfügbar'));
    $this->app->instance(SupportAttachmentManager::class, $manager);
    $this->withoutExceptionHandling();
    $this->actingAs($requester);

    expect(fn () => $this->post(route('support.tickets.reply', $ticket), [
        'message' => '',
        'attachments' => [
            UploadedFile::fake()->create('nachweis.pdf', 1, 'application/pdf'),
        ],
    ]))->toThrow(RuntimeException::class, 'Speicher nicht verfügbar');

    expect($ticket->messages()->count())->toBe(0)
        ->and($ticket->fresh()->last_reply_at?->equalTo($originalLastReplyAt))->toBeTrue();
});

it('rolls back admin replies and ticket changes when storing an attachment fails', function () {
    $support = User::factory()->create([
        'role' => UserRole::Support,
        'email_verified_at' => now(),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-07',
        'subject' => 'Transaktion Supportantwort',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'last_reply_at' => now()->subDay(),
    ]);
    $originalLastReplyAt = $ticket->last_reply_at;
    $manager = Mockery::mock(SupportAttachmentManager::class);
    $manager->shouldReceive('storeUploads')
        ->once()
        ->andThrow(new RuntimeException('Speicher nicht verfügbar'));
    $this->app->instance(SupportAttachmentManager::class, $manager);
    $this->withoutExceptionHandling();
    $this->actingAs($support);

    expect(fn () => $this->post(route('admin.support.reply', $ticket), [
        'body' => '',
        'is_internal' => false,
        'attachments' => [
            UploadedFile::fake()->create('antwort.pdf', 1, 'application/pdf'),
        ],
    ]))->toThrow(RuntimeException::class, 'Speicher nicht verfügbar');

    $ticket->refresh();
    expect($ticket->messages()->count())->toBe(0)
        ->and($ticket->assigned_to)->toBeNull()
        ->and($ticket->status)->toBe(SupportTicketStatus::Open)
        ->and($ticket->last_reply_at?->equalTo($originalLastReplyAt))->toBeTrue();
});

it('rejects a Zammad download as soon as its streamed size exceeds the limit', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set('support.attachments.max_kilobytes', 1);
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_attachment/702/802/902' => Http::response(
            str_repeat('x', 1025),
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-08',
        'subject' => 'Zu großer Zammad-Anhang',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '702',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '802',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Antwort aus Zammad',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '902',
        'original_name' => 'zu-gross.pdf',
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldNotReceive('scan');

    (new ImportZammadAttachment($attachment->getKey()))->handle(
        new ZammadTicketingProvider,
        $scanner,
        app(SupportAttachmentLimits::class),
    );

    expect($attachment->fresh())
        ->scan_result->toBe('rejected')
        ->disk->toBeNull()
        ->path->toBeNull();
});

it('rejects a Zammad attachment when its actual message total exceeds the limit', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set('support.attachments.max_kilobytes', 10);
    config()->set('support.attachments.max_total_kilobytes', 15);
    $pdf = "%PDF-1.4\n".str_repeat(' ', (8 * 1024) - 15)."\n%%EOF";
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_attachment/703/803/903' => Http::response(
            $pdf,
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-08B',
        'subject' => 'Zammad-Gesamtlimit',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '703',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '803',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Mehrere Anhänge aus Zammad',
        'is_internal' => false,
    ]);
    $message->files()->create([
        'source' => 'zammad',
        'external_id' => 'already-imported',
        'original_name' => 'vorhanden.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 8 * 1024,
        'checksum_sha256' => hash('sha256', str_repeat('x', 8 * 1024)),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '903',
        'original_name' => 'weiterer.pdf',
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldNotReceive('scan');

    (new ImportZammadAttachment($attachment->getKey()))->handle(
        new ZammadTicketingProvider,
        $scanner,
        app(SupportAttachmentLimits::class),
    );

    expect($attachment->fresh())
        ->scan_result->toBe('rejected')
        ->disk->toBeNull()
        ->path->toBeNull();
});

it('refuses changed support attachments during both sending and download', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    Http::fake();
    $requester = User::factory()->create(['email_verified_at' => now()]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-09',
        'subject' => 'Integrität',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Bitte prüfen.',
        'is_internal' => false,
    ]);
    Storage::disk('private')->put('support-tickets/original.pdf', 'original');
    $attachment = $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => 'support-tickets/original.pdf',
        'original_name' => 'original.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('original'),
        'checksum_sha256' => hash('sha256', 'original'),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    Storage::disk('private')->put('support-tickets/original.pdf', 'manipuliert');

    expect(fn () => (new ZammadTicketingProvider)->createTicket($ticket, $message))
        ->toThrow(SupportAttachmentIntegrityException::class);
    Http::assertNothingSent();

    $downloadUrl = app(SupportTicketMessagePresenter::class)
        ->present($message->fresh())['attachments'][0]['download_url'];
    $this->actingAs($requester)
        ->get((string) $downloadUrl)
        ->assertConflict();
});

it('enforces the aggregate limit again when building the Zammad payload', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set('support.attachments.max_kilobytes', 10);
    config()->set('support.attachments.max_total_kilobytes', 15);
    Http::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-10',
        'subject' => 'Gesamtlimit Versand',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Zwei Anhänge.',
        'is_internal' => false,
    ]);
    foreach (['eins.pdf', 'zwei.pdf'] as $index => $name) {
        $contents = str_repeat((string) $index, 8 * 1024);
        $path = "support-tickets/{$name}";
        Storage::disk('private')->put($path, $contents);
        $message->files()->create([
            'uploaded_by' => $requester->getKey(),
            'source' => 'erin',
            'disk' => 'private',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_result' => 'clean',
            'scan_completed_at' => now(),
        ]);
    }

    expect(fn () => (new ZammadTicketingProvider)->createTicket($ticket, $message))
        ->toThrow(SupportAttachmentLimitExceeded::class);
    Http::assertNothingSent();
});

it('requires an unexpired untampered signed URL and the correct tenant for downloads', function () {
    $requester = User::factory()->create(['email_verified_at' => now()]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-AUTH',
        'subject' => 'Download-Autorisierung',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Privater Inhalt.',
        'is_internal' => false,
    ]);
    Storage::disk('private')->put('support-tickets/private.pdf', 'private-content');
    $attachment = $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => 'support-tickets/private.pdf',
        'original_name' => 'private.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('private-content'),
        'checksum_sha256' => hash('sha256', 'private-content'),
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $signedUrl = URL::temporarySignedRoute(
        'support.attachments.download',
        now()->addMinutes(10),
        ['attachment' => $attachment->getKey()],
    );
    $expiredUrl = URL::temporarySignedRoute(
        'support.attachments.download',
        now()->subMinute(),
        ['attachment' => $attachment->getKey()],
    );
    $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=tampered', $signedUrl);

    $this->actingAs($requester)
        ->get(route('support.attachments.download', $attachment))
        ->assertForbidden();
    $this->actingAs($requester)
        ->get($expiredUrl)
        ->assertForbidden();
    $this->actingAs($requester)
        ->get((string) $tamperedUrl)
        ->assertForbidden();

    $otherTenant = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($otherTenant)
        ->get($signedUrl)
        ->assertForbidden();

    $this->actingAs($requester)
        ->get($signedUrl)
        ->assertOk()
        ->assertStreamedContent('private-content');
});

it('deletes an infected attachment imported from Zammad before exposing it', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    $payload = "%PDF-1.4\ninfected-test-content\n%%EOF";
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_attachment/704/804/904' => Http::response(
            $payload,
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-ZAMMAD-MALWARE',
        'subject' => 'Zammad-Malware',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '704',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '804',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Verdächtiger Anhang',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '904',
        'original_name' => 'verdacht.pdf',
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('infected');

    (new ImportZammadAttachment($attachment->getKey()))->handle(
        new ZammadTicketingProvider,
        $scanner,
        app(SupportAttachmentLimits::class),
    );

    expect($attachment->fresh())
        ->scan_result->toBe('infected')
        ->disk->toBeNull()
        ->path->toBeNull()
        ->checksum_sha256->toBe(hash('sha256', $payload))
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('fails closed and cleans up when ClamAV or the Zammad attachment API is unavailable', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    Http::fakeSequence()
        ->push(['error' => 'provider unavailable'], 503)
        ->push(['error' => 'provider unavailable'], 503)
        ->push(['error' => 'provider unavailable'], 503);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-PROVIDER-OUTAGE',
        'subject' => 'Provider-Ausfall',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '705',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '805',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Anhang bei Provider-Ausfall',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '905',
        'original_name' => 'ausfall.pdf',
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldNotReceive('scan');
    $job = new ImportZammadAttachment($attachment->getKey());

    expect(fn () => $job->handle(
        new ZammadTicketingProvider,
        $scanner,
        app(SupportAttachmentLimits::class),
    ))->toThrow(RequestException::class);
    $job->failed(new RuntimeException('Provider weiterhin nicht verfügbar'));

    expect($attachment->fresh())
        ->scan_result->toBe('scan_failed')
        ->disk->toBeNull()
        ->path->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([]);
    Http::assertSentCount(3);

    Storage::disk('private')->put('support-tickets/clamav-outage.pdf', 'clean-looking');
    $localMessage = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'source' => 'erin',
        'delivery_status' => 'pending',
        'body' => 'Lokaler Scan-Ausfall',
        'is_internal' => false,
    ]);
    $localAttachment = $localMessage->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => 'support-tickets/clamav-outage.pdf',
        'original_name' => 'clamav-outage.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('clean-looking'),
        'checksum_sha256' => hash('sha256', 'clean-looking'),
        'scan_result' => 'pending',
    ]);
    $unavailableScanner = Mockery::mock(ClamAvScanner::class);
    $unavailableScanner->shouldReceive('scan')
        ->once()
        ->andThrow(new RuntimeException('ClamAV nicht erreichbar'));
    $scanJob = new ScanSupportTicketAttachment($localAttachment->getKey());

    expect(fn () => $scanJob->handle($unavailableScanner))
        ->toThrow(RuntimeException::class, 'ClamAV nicht erreichbar');
    $scanJob->failed(new RuntimeException('ClamAV nicht erreichbar'));

    expect($localAttachment->fresh())
        ->scan_result->toBe('scan_failed')
        ->and($localMessage->fresh()->delivery_status)->toBe('failed');
    Storage::disk('private')->assertMissing('support-tickets/clamav-outage.pdf');
});

it('rejects MIME-spoofed Zammad files without invoking ClamAV', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    Http::fake([
        'https://zammad.example.test/api/v1/ticket_attachment/706/806/906' => Http::response(
            '<html><script>alert("not a pdf")</script></html>',
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-MIME-SPOOF',
        'subject' => 'MIME-Spoofing',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '706',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '806',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'MIME-Spoof',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '906',
        'original_name' => 'getarnt.pdf',
        'mime_type' => 'application/pdf',
        'scan_result' => 'pending',
    ]);
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldNotReceive('scan');

    (new ImportZammadAttachment($attachment->getKey()))->handle(
        new ZammadTicketingProvider,
        $scanner,
        app(SupportAttachmentLimits::class),
    );

    expect($attachment->fresh())
        ->scan_result->toBe('rejected')
        ->disk->toBeNull()
        ->path->toBeNull();
});

it('enforces the attachment count again immediately before a Zammad write', function () {
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://zammad.example.test');
    config()->set('services.zammad.token', 'zammad-test-token');
    config()->set('support.attachments.max_files', 1);
    Http::fake();
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-COUNT-EGRESS',
        'subject' => 'Dateianzahl beim Versand',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Zu viele Anhänge.',
        'is_internal' => false,
    ]);
    foreach (['eins.pdf', 'zwei.pdf'] as $name) {
        $contents = "%PDF-1.4\n{$name}\n%%EOF";
        $path = "support-tickets/{$name}";
        Storage::disk('private')->put($path, $contents);
        $message->files()->create([
            'uploaded_by' => $requester->getKey(),
            'source' => 'erin',
            'disk' => 'private',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'scan_result' => 'clean',
            'scan_completed_at' => now(),
        ]);
    }

    expect(fn () => (new ZammadTicketingProvider)->createTicket($ticket, $message))
        ->toThrow(SupportAttachmentLimitExceeded::class);
    Http::assertNothingSent();
});

it('releases both synchronization jobs when their distributed lock is held', function () {
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-11',
        'subject' => 'Synchronisationssperre',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => 'Wird parallel synchronisiert.',
        'is_internal' => false,
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $locks = app(SupportSyncLock::class);

    $ticketLock = $locks->forTicket($ticket->getKey());
    expect($ticketLock->get())->toBeTrue();
    try {
        $ticketJob = (new SyncSupportTicketToProvider($ticket->getKey()))
            ->withFakeQueueInteractions();
        $ticketJob->handle($provider);
        $ticketJob->assertReleased($locks->retrySeconds());
    } finally {
        $ticketLock->release();
    }

    $messageLock = $locks->forMessage($message->getKey());
    expect($messageLock->get())->toBeTrue();
    try {
        $messageJob = (new SyncSupportMessageToProvider($message->getKey()))
            ->withFakeQueueInteractions();
        $messageJob->handle($provider);
        $messageJob->assertReleased($locks->retrySeconds());
    } finally {
        $messageLock->release();
    }
});

it('deletes a deterministic Zammad file when persisting its database reference fails', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-DB-FAILURE',
        'subject' => 'Datenbankfehler nach Dateiablage',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'external_system' => 'zammad',
        'external_id' => '707',
    ]);
    $message = $ticket->messages()->create([
        'external_article_id' => '807',
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Anhang vor Datenbankfehler',
        'is_internal' => false,
    ]);
    $attachment = $message->files()->create([
        'source' => 'zammad',
        'external_id' => '907',
        'original_name' => 'datenbankfehler.pdf',
        'scan_result' => 'pending',
    ]);
    $provider = Mockery::mock(TicketingProvider::class);
    $provider->shouldReceive('downloadAttachment')
        ->once()
        ->andReturnUsing(function (
            string $ticketId,
            string $articleId,
            string $attachmentId,
            mixed $destination,
        ) use ($pdf): array {
            expect($ticketId)->toBe('707')
                ->and($articleId)->toBe('807')
                ->and($attachmentId)->toBe('907');
            fwrite($destination, $pdf);

            return [
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'checksum_sha256' => hash('sha256', $pdf),
            ];
        });
    $scanner = Mockery::mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');
    $eventName = 'eloquent.updating: '.SupportTicketAttachment::class;
    Event::listen($eventName, static function (SupportTicketAttachment $updating): void {
        if ($updating->isDirty('path')) {
            throw new RuntimeException('Simulierter Datenbankfehler nach Dateiablage.');
        }
    });

    try {
        expect(fn () => (new ImportZammadAttachment($attachment->getKey()))
            ->handle(
                $provider,
                $scanner,
                app(SupportAttachmentLimits::class),
            ))->toThrow(RuntimeException::class, 'Simulierter Datenbankfehler');
    } finally {
        Event::forget($eventName);
    }

    expect($attachment->fresh())
        ->scan_result->toBe('pending')
        ->disk->toBeNull()
        ->path->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('prunes only expired unreferenced deterministic Zammad files', function () {
    config()->set('support.attachments.orphan_grace_hours', 24);
    $requester = User::factory()->create();
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-ATTACHMENT-ORPHAN-PRUNE',
        'subject' => 'Verwaiste Dateien',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
    ]);
    $message = $ticket->messages()->create([
        'source' => 'zammad',
        'delivery_status' => 'delivered',
        'body' => 'Dateireferenzen',
        'is_internal' => false,
    ]);
    $referencedPath = sprintf(
        'support-tickets/%d/zammad/1001.pdf',
        $ticket->getKey(),
    );
    $expiredOrphan = sprintf(
        'support-tickets/%d/zammad/1002.pdf',
        $ticket->getKey(),
    );
    $youngOrphan = sprintf(
        'support-tickets/%d/zammad/1003.pdf',
        $ticket->getKey(),
    );
    $unmanagedPath = sprintf(
        'support-tickets/%d/manual/1004.pdf',
        $ticket->getKey(),
    );
    $message->files()->create([
        'source' => 'zammad',
        'external_id' => '1001',
        'disk' => 'private',
        'path' => $referencedPath,
        'original_name' => 'referenziert.pdf',
        'scan_result' => 'clean',
    ]);
    foreach ([$referencedPath, $expiredOrphan, $youngOrphan, $unmanagedPath] as $path) {
        Storage::disk('private')->put($path, 'pdf');
    }
    $expiredAt = now()->subHours(25)->getTimestamp();
    touch(Storage::disk('private')->path($referencedPath), $expiredAt);
    touch(Storage::disk('private')->path($expiredOrphan), $expiredAt);
    touch(Storage::disk('private')->path($unmanagedPath), $expiredAt);
    $cleaner = app(SupportAttachmentOrphanCleaner::class);

    expect($cleaner->prune(false))->toBe([
        'scanned' => 3,
        'eligible' => 1,
        'deleted' => 0,
        'metadata_errors' => 0,
        'deletion_errors' => 0,
        'eligible_paths' => [$expiredOrphan],
    ]);
    Storage::disk('private')->assertExists($expiredOrphan);

    expect($cleaner->prune(true))->toBe([
        'scanned' => 3,
        'eligible' => 1,
        'deleted' => 1,
        'metadata_errors' => 0,
        'deletion_errors' => 0,
        'eligible_paths' => [$expiredOrphan],
    ]);
    Storage::disk('private')->assertMissing($expiredOrphan);
    Storage::disk('private')->assertExists($referencedPath);
    Storage::disk('private')->assertExists($youngOrphan);
    Storage::disk('private')->assertExists($unmanagedPath);
});

it('fails the orphan-prune command when object metadata cannot be read', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('allFiles')
        ->once()
        ->with('support-tickets')
        ->andReturn(['support-tickets/1/zammad/1001.pdf']);
    $disk->shouldReceive('lastModified')
        ->once()
        ->with('support-tickets/1/zammad/1001.pdf')
        ->andThrow(new RuntimeException('Metadaten nicht verfügbar'));
    $disk->shouldNotReceive('delete');
    Storage::shouldReceive('disk')
        ->once()
        ->with('private')
        ->andReturn($disk);

    $exitCode = Artisan::call('erin:support:prune-orphan-attachments', [
        '--execute' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('"status": "failed"')
        ->toContain('"metadata_errors": 1')
        ->toContain('"deletion_errors": 0');
});

it('fails the orphan-prune command when deleting an eligible object fails', function () {
    $path = 'support-tickets/1/zammad/1002.pdf';
    $disk = Mockery::mock();
    $disk->shouldReceive('allFiles')
        ->once()
        ->with('support-tickets')
        ->andReturn([$path]);
    $disk->shouldReceive('lastModified')
        ->once()
        ->with($path)
        ->andReturn(now()->subDays(2)->getTimestamp());
    $disk->shouldReceive('delete')
        ->once()
        ->with($path)
        ->andReturnFalse();
    Storage::shouldReceive('disk')
        ->once()
        ->with('private')
        ->andReturn($disk);

    $exitCode = Artisan::call('erin:support:prune-orphan-attachments', [
        '--execute' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('"status": "failed"')
        ->toContain('"eligible": 1')
        ->toContain('"deleted": 0')
        ->toContain('"deletion_errors": 1');
});
