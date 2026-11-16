<?php

namespace App\Console\Commands;

use App\Enums\VisaStepStatus;
use App\Models\JobPosting;
use App\Models\VisaStep;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendOperationalNotifications extends Command
{
    protected $signature = 'erin:notifications:operational {--limit=500}';

    protected $description = 'Send idempotent visa-deadline and boost-availability notifications';

    public function handle(ProductNotificationDispatcher $dispatcher): int
    {
        $limit = max(1, min(2000, (int) $this->option('limit')));
        $sent = 0;

        VisaStep::query()
            ->with(['visaCase.candidateProfile.user'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', now()->addDays(3))
            ->whereNotIn('status', [VisaStepStatus::Completed, VisaStepStatus::NotRequired])
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->each(function (VisaStep $step) use ($dispatcher, &$sent): void {
                $user = $step->visaCase->candidateProfile->user;
                $dueValue = $step->getAttribute('due_at');
                $due = $dueValue === null ? 'unknown' : Carbon::parse($dueValue)->toDateString();
                $sent += (int) $dispatcher->dispatch(
                    $user,
                    'visa.deadline',
                    "visa-step:{$step->getKey()}:deadline:{$due}",
                    [
                        'title' => 'Visa-Frist steht an',
                        'message' => "Ein Schritt im Visa-Prozess ist am {$due} fällig.",
                        'translations' => [
                            'de' => ['title' => 'Visa-Frist steht an', 'message' => "Ein Schritt im Visa-Prozess ist am {$due} fällig."],
                            'en' => ['title' => 'Visa deadline approaching', 'message' => "A step in your visa process is due on {$due}."],
                        ],
                        'url' => route('dashboard'),
                        'visa_case_id' => $step->visa_case_id,
                        'visa_step_id' => $step->getKey(),
                    ],
                );
            });

        JobPosting::query()
            ->with('company.users')
            ->whereNotNull('boosted_until')
            ->where('boosted_until', '<=', now())
            ->orderBy('boosted_until')
            ->limit($limit)
            ->get()
            ->each(function (JobPosting $job) use ($dispatcher, &$sent): void {
                $expiry = $job->boosted_until?->toIso8601String() ?? 'unknown';
                foreach ($job->company->users()->wherePivotNotNull('accepted_at')->get() as $user) {
                    $sent += (int) $dispatcher->dispatch(
                        $user,
                        'boost.available',
                        "job:{$job->getKey()}:boost-expired:{$expiry}",
                        [
                            'title' => 'Stellen-Boost wieder verfügbar',
                            'message' => 'Die Hervorhebung einer Stellenanzeige ist abgelaufen. Ein neuer Boost kann eingesetzt werden.',
                            'translations' => [
                                'de' => ['title' => 'Stellen-Boost wieder verfügbar', 'message' => 'Die Hervorhebung einer Stellenanzeige ist abgelaufen. Ein neuer Boost kann eingesetzt werden.'],
                                'en' => ['title' => 'Job boost available again', 'message' => 'A job highlight has expired. A new boost can now be used.'],
                            ],
                            'url' => route('employer.jobs.index'),
                            'job_posting_id' => $job->getKey(),
                        ],
                    );
                }
            });

        $this->info("{$sent} operationale Benachrichtigung(en) eingeplant.");

        return self::SUCCESS;
    }
}
