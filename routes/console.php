<?php

use App\Enums\CandidateDocumentStatus;
use App\Models\CandidateDocument;
use App\Models\JobInvitation;
use App\Services\Ticketing\SupportOutboundReconciliationDispatcher;
use App\Services\Ticketing\SupportWebhookOutboxDispatcher;
use App\Services\Ticketing\SupportZammadWebhookInboxDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    CandidateDocument::query()
        ->where('expires_at', '<=', now())
        ->whereNotIn('status', [
            CandidateDocumentStatus::Rejected,
            CandidateDocumentStatus::Expired,
        ])
        ->update(['status' => CandidateDocumentStatus::Expired]);

    JobInvitation::query()
        ->where('status', 'pending')
        ->where('expires_at', '<=', now())
        ->update(['status' => 'expired', 'responded_at' => now()]);

    DB::table('document_access_grants')
        ->whereNull('revoked_at')
        ->where('expires_at', '<=', now())
        ->update(['revoked_at' => now(), 'updated_at' => now()]);

    DB::table('upload_reservations')
        ->where('expires_at', '<=', now())
        ->delete();
})->name('erin:expire-sensitive-access')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('telescope:prune --hours=72')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:reminders:send-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:ops:queue-health --json')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::call(
    fn (): bool => Cache::put('erin:ops:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(3)),
)->name('erin:ops:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:ops:prune --execute --json')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:storage:prune --execute --json')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:audit:prune --execute --json')
    ->dailyAt('03:40')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:audit:detect-anomalies --json')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:stripe:reconcile-billing --limit=100')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::call(
    fn (): int => app(SupportWebhookOutboxDispatcher::class)->dispatchPending(100),
)->name('erin:support:dispatch-webhook-outbox')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::call(
    fn (): array => app(SupportOutboundReconciliationDispatcher::class)
        ->dispatchDue(100),
)->name('erin:support:reconcile-zammad-writes')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::call(
    fn (): int => app(SupportZammadWebhookInboxDispatcher::class)
        ->dispatchPending(100),
)->name('erin:support:replay-unmatched-zammad-webhooks')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('erin:support:prune-orphan-attachments --execute --json')
    ->dailyAt('03:45')
    ->onOneServer()
    ->withoutOverlapping();
