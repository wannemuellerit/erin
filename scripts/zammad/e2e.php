<?php

declare(strict_types=1);

use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Jobs\ScanSupportTicketAttachment;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use App\Services\Documents\ClamAvScanner;
use App\Services\Ticketing\SupportAttachmentIntegrityVerifier;
use App\Services\Ticketing\ZammadTicketingProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment(['local', 'testing'])) {
    fwrite(STDERR, "Der schreibende Zammad-E2E-Test ist nur lokal oder in Tests erlaubt.\n");

    exit(1);
}

$statePath = __DIR__.'/../../docker/zammad/runtime/e2e-state.json';
$action = $argv[1] ?? '';

if ($action === 'prepare') {
    Queue::fake();

    $requester = User::query()
        ->where('role', UserRole::Candidate)
        ->whereNotNull('email_verified_at')
        ->orderBy('id')
        ->first();
    if ($requester === null) {
        fwrite(STDERR, "Für den E2E-Test fehlt eine verifizierte Demo-Fachkraft.\n");

        exit(1);
    }

    $marker = 'ERIN-ZAMMAD-E2E-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => $marker,
        'subject' => 'Automatischer Zammad-E2E-Test',
        'category' => 'technical_test',
        'priority' => 'normal',
        'status' => SupportTicketStatus::Open,
        'sync_status' => 'pending',
        'last_reply_at' => now(),
    ]);
    $message = $ticket->messages()->create([
        'author_id' => $requester->getKey(),
        'body' => "Automatischer lokaler Supporttest {$marker}.",
        'is_internal' => false,
        'source' => 'erin',
        'delivery_status' => 'pending',
    ]);

    $outboundContents = "%PDF-1.4\n% Erin Zammad E2E {$marker}\n%%EOF";
    $outboundPath = "support-tickets/{$ticket->getKey()}/erin/e2e-outbound.pdf";
    if (! Storage::disk('private')->put($outboundPath, $outboundContents)) {
        fwrite(STDERR, "Der E2E-Testanhang konnte nicht privat gespeichert werden.\n");

        exit(1);
    }
    $attachment = $message->files()->create([
        'uploaded_by' => $requester->getKey(),
        'source' => 'erin',
        'disk' => 'private',
        'path' => $outboundPath,
        'original_name' => 'erin-e2e-ausgang.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($outboundContents),
        'checksum_sha256' => hash('sha256', $outboundContents),
        'scan_result' => 'pending',
    ]);

    (new ScanSupportTicketAttachment($attachment->getKey()))
        ->handle($app->make(ClamAvScanner::class));
    (new SyncSupportTicketToProvider($ticket->getKey()))
        ->handle($app->make(ZammadTicketingProvider::class));

    $ticket->refresh();
    $message->refresh();
    $attachment->refresh();
    if (
        $ticket->external_id === null
        || $message->external_article_id === null
        || $attachment->scan_result !== 'clean'
    ) {
        fwrite(STDERR, "Das Erin-Ticket wurde nicht vollständig nach Zammad übertragen.\n");

        exit(1);
    }

    $state = [
        'marker' => $marker,
        'ticket_id' => $ticket->getKey(),
        'external_ticket_id' => $ticket->external_id,
        'external_opening_article_id' => $message->external_article_id,
        'outbound_attachment_sha256' => hash('sha256', $outboundContents),
    ];
    $directory = dirname($statePath);
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents(
        $statePath,
        json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
        LOCK_EX,
    );
    chmod($statePath, 0600);

    fwrite(STDOUT, json_encode([
        'prepared' => true,
        'ticket_id' => $ticket->getKey(),
        'external_ticket_id' => $ticket->external_id,
        'outbound_attachment' => 'clean',
    ], JSON_THROW_ON_ERROR).PHP_EOL);

    exit(0);
}

if ($action === 'verify') {
    if (! is_file($statePath)) {
        fwrite(STDERR, "Der lokale E2E-Zustand fehlt.\n");

        exit(1);
    }
    $state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    $articleId = (string) ($state['external_reply_article_id'] ?? '');
    $expectedBody = (string) ($state['reply_body'] ?? '');
    $expectedChecksum = (string) ($state['reply_attachment_sha256'] ?? '');
    if ($articleId === '' || $expectedBody === '' || $expectedChecksum === '') {
        fwrite(STDERR, "Der lokale E2E-Zustand ist unvollständig.\n");

        exit(1);
    }

    $ticket = SupportTicket::query()->findOrFail((int) $state['ticket_id']);
    $replyQuery = $ticket->messages()
        ->where('external_article_id', $articleId);
    $replyCount = $replyQuery->count();
    if ($replyCount === 0) {
        exit(75);
    }
    if ($replyCount !== 1) {
        fwrite(STDERR, "Die Zammad-Antwort wurde nicht genau einmal nach Erin importiert.\n");

        exit(1);
    }
    $message = $replyQuery
        ->with('files')
        ->sole();
    if ($message->body !== $expectedBody) {
        exit(75);
    }
    if ($message->files->count() !== 1) {
        fwrite(STDERR, "Der Zammad-Testanhang wurde nicht genau einmal nach Erin importiert.\n");

        exit(1);
    }
    /** @var SupportTicketAttachment|null $attachment */
    $attachment = $message->files->first();
    if (
        $attachment === null
        || $attachment->scan_result !== 'clean'
        || ! filled($attachment->path)
    ) {
        exit(75);
    }

    $contents = $app->make(SupportAttachmentIntegrityVerifier::class)
        ->verifiedContents($attachment);
    if (! hash_equals($expectedChecksum, hash('sha256', $contents))) {
        fwrite(STDERR, "Der importierte Zammad-Anhang stimmt nicht mit dem Testinhalt überein.\n");

        exit(1);
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'ticket_id' => $ticket->getKey(),
        'external_ticket_id' => $ticket->external_id,
        'reply_article_id' => $articleId,
        'reply_count' => $replyCount,
        'reply_attachment_count' => $message->files->count(),
        'reply_attachment' => 'clean',
    ], JSON_THROW_ON_ERROR).PHP_EOL);

    exit(0);
}

error('Verwendung: php scripts/zammad/e2e.php prepare|verify');
exit(2);
