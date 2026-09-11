<?php

namespace App\Console\Commands;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Models\User;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Console\Command;

class SendInterviewReminders extends Command
{
    protected $signature = 'erin:interviews:send-reminders {--limit=250}';

    protected $description = 'Send idempotent 24-hour and one-hour interview reminders';

    public function handle(ProductNotificationDispatcher $notifications): int
    {
        $sent = 0;
        Interview::query()
            ->where('status', InterviewStatus::Confirmed)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(24))
            ->with([
                'organizer',
                'application.candidateProfile.user',
                'application.jobPosting:id,title,created_by',
            ])
            ->orderBy('starts_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get()
            ->each(function (Interview $interview) use ($notifications, &$sent): void {
                $minutes = now()->diffInMinutes($interview->starts_at, false);
                $window = $minutes <= 60 ? '1h' : '24h';
                $participants = collect([
                    $interview->application->candidateProfile->user,
                    $interview->organizer ?? $interview->application->jobPosting->creator,
                ])->filter()->unique(fn (User $user): int => (int) $user->getKey());

                foreach ($participants as $participant) {
                    if ($notifications->dispatch(
                        $participant,
                        'interview.reminder',
                        "interview:{$interview->getKey()}:reminder:{$window}",
                        [
                            'title' => __('Interview-Erinnerung'),
                            'message' => $window === '1h'
                                ? __('Dein Interview beginnt in weniger als einer Stunde.')
                                : __('Dein Interview findet innerhalb der nächsten 24 Stunden statt.'),
                            'translations' => [
                                'de' => [
                                    'title' => 'Interview-Erinnerung',
                                    'message' => $window === '1h'
                                        ? 'Dein Interview beginnt in weniger als einer Stunde.'
                                        : 'Dein Interview findet innerhalb der nächsten 24 Stunden statt.',
                                ],
                                'en' => [
                                    'title' => 'Interview reminder',
                                    'message' => $window === '1h'
                                        ? 'Your interview starts in less than one hour.'
                                        : 'Your interview takes place within the next 24 hours.',
                                ],
                            ],
                            'url' => route('interviews.index'),
                            'interview_id' => $interview->getKey(),
                            'window' => $window,
                        ],
                    )) {
                        $sent++;
                    }
                }
            });

        $this->info("Interview reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
