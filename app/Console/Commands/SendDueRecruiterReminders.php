<?php

namespace App\Console\Commands;

use App\Models\RecruiterReminder;
use App\Notifications\ActivityNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDueRecruiterReminders extends Command
{
    protected $signature = 'erin:reminders:send-due {--limit=250}';

    protected $description = 'Send notifications for due recruiter reminders';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $sent = 0;

        RecruiterReminder::query()
            ->open()
            ->whereNull('notified_at')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$sent): void {
                DB::transaction(function () use ($id, &$sent): void {
                    /** @var RecruiterReminder|null $reminder */
                    $reminder = RecruiterReminder::query()
                        ->with('assignee')
                        ->lockForUpdate()
                        ->find($id);

                    if (
                        $reminder === null
                        || $reminder->completed_at !== null
                        || $reminder->notified_at !== null
                    ) {
                        return;
                    }

                    $reminder->assignee->notify(new ActivityNotification([
                        'event' => 'reminder.due',
                        'translations' => [
                            'de' => [
                                'title' => 'Erinnerung ist fällig',
                                'message' => $reminder->title,
                            ],
                            'en' => [
                                'title' => 'Reminder is due',
                                'message' => $reminder->title,
                            ],
                        ],
                        'url' => route('employer.productivity'),
                        'reminder_id' => $reminder->getKey(),
                    ]));
                    $reminder->update(['notified_at' => now()]);
                    $sent++;
                }, 3);
            });

        $this->info("{$sent} Erinnerung(en) versendet.");

        return self::SUCCESS;
    }
}
