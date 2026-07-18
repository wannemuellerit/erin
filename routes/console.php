<?php

use App\Enums\CandidateDocumentStatus;
use App\Models\CandidateDocument;
use App\Models\JobInvitation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
