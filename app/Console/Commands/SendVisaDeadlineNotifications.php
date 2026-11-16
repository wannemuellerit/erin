<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VisaTask;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Console\Command;

class SendVisaDeadlineNotifications extends Command
{
    protected $signature = 'erin:visa:send-deadline-notifications {--limit=250}';

    protected $description = 'Send idempotent Visa task due-date reminders and overdue escalations';

    public function handle(ProductNotificationDispatcher $notifications): int
    {
        $sent = 0;
        VisaTask::query()
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', today()->addDay())
            ->with(['assignee', 'step.visaCase.assignee'])
            ->orderBy('due_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get()
            ->each(function (VisaTask $task) use ($notifications, &$sent): void {
                $overdue = $task->due_at?->isBefore(today()) === true;
                $window = $overdue ? 'overdue' : 'due';
                $recipients = collect([
                    $task->assignee,
                    $task->step->visaCase->assignee,
                ])->filter()->unique(fn (User $user): int => (int) $user->getKey());
                if ($overdue && $task->due_at->isBefore(today()->subDay())) {
                    $recipients->push(...User::query()
                        ->where('role', 'super_admin')
                        ->where('status', 'active')
                        ->get());
                }

                foreach ($recipients->unique(fn (User $user): int => (int) $user->getKey()) as $recipient) {
                    if ($notifications->dispatch(
                        $recipient,
                        'visa.deadline',
                        "visa-task:{$task->getKey()}:{$window}:".today()->toDateString(),
                        [
                            'title' => __('Visa-Frist'),
                            'message' => $overdue
                                ? __('Eine Visa-Aufgabe ist überfällig und erfordert eine Aktion.')
                                : __('Eine Visa-Aufgabe ist innerhalb des nächsten Tages fällig.'),
                            'translations' => [
                                'de' => [
                                    'title' => 'Visa-Frist',
                                    'message' => $overdue
                                        ? 'Eine Visa-Aufgabe ist überfällig und erfordert eine Aktion.'
                                        : 'Eine Visa-Aufgabe ist innerhalb des nächsten Tages fällig.',
                                ],
                                'en' => [
                                    'title' => 'Visa deadline',
                                    'message' => $overdue
                                        ? 'A visa task is overdue and requires action.'
                                        : 'A visa task is due within the next day.',
                                ],
                            ],
                            'url' => $recipient->role->value === 'company'
                                ? route('employer.visa')
                                : route('admin.visa.index'),
                            'visa_case_id' => $task->step->visa_case_id,
                            'visa_task_id' => $task->getKey(),
                            'window' => $window,
                        ],
                    )) {
                        $sent++;
                    }
                }
                $task->forceFill(['last_reminded_at' => now()])->save();
            });

        $this->info("Visa deadline notifications sent: {$sent}");

        return self::SUCCESS;
    }
}
