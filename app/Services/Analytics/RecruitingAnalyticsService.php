<?php

namespace App\Services\Analytics;

use App\Enums\ApplicationStatus;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecruitingAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function forCompany(Company $company, Carbon $from, Carbon $to): array
    {
        $applications = $this->applications($company, $from, $to)
            ->with([
                'candidateProfile:id,current_country_code',
                'jobPosting:id,title',
                'interviews:id,application_id',
            ])
            ->get();
        $total = $applications->count();
        $interviewed = $applications->filter(
            fn (JobApplication $application): bool => $application->interviews->isNotEmpty(),
        )->count();
        $hired = $applications->where('status', ApplicationStatus::Hired)->count();
        $daysToHire = $applications
            ->where('status', ApplicationStatus::Hired)
            ->map(function (JobApplication $application): ?float {
                $completedAt = $application->decided_at ?? $application->updated_at;

                return $completedAt === null
                    ? null
                    : abs($application->applied_at->diffInSeconds($completedAt)) / 86400;
            })
            ->filter(fn (?float $days): bool => $days !== null);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => [
                'applications' => $total,
                'interviews' => $interviewed,
                'hires' => $hired,
                'interview_rate' => $total > 0 ? round($interviewed / $total * 100, 1) : 0,
                'hire_rate' => $total > 0 ? round($hired / $total * 100, 1) : 0,
                'average_days_to_hire' => $daysToHire->isNotEmpty()
                    ? round((float) $daysToHire->average(), 1)
                    : null,
            ],
            'jobs' => $this->jobs($company, $applications),
            'countries' => $this->countries($applications),
            'timeline' => $this->timeline($applications, $from, $to),
        ];
    }

    /**
     * @return Builder<JobApplication>
     */
    private function applications(Company $company, Carbon $from, Carbon $to): Builder
    {
        return JobApplication::query()
            ->whereHas(
                'jobPosting',
                fn (Builder $query): Builder => $query->where('company_id', $company->getKey()),
            )
            ->whereBetween('applied_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * @param  Collection<int, JobApplication>  $applications
     * @return list<array<string, int|float|string>>
     */
    private function jobs(Company $company, Collection $applications): array
    {
        return array_values(JobPosting::query()
            ->where('company_id', $company->getKey())
            ->latest()
            ->get(['id', 'title', 'status'])
            ->map(function (JobPosting $job) use ($applications): array {
                $rows = $applications->where('job_posting_id', $job->getKey());
                $count = $rows->count();
                $interviews = $rows->filter(
                    fn (JobApplication $application): bool => $application->interviews->isNotEmpty(),
                )->count();
                $hires = $rows->where('status', ApplicationStatus::Hired)->count();

                return [
                    'id' => $job->getKey(),
                    'title' => $job->title,
                    'status' => $job->status->value,
                    'applications' => $count,
                    'interviews' => $interviews,
                    'hires' => $hires,
                    'interview_rate' => $count > 0 ? round($interviews / $count * 100, 1) : 0,
                    'hire_rate' => $count > 0 ? round($hires / $count * 100, 1) : 0,
                ];
            })
            ->sortByDesc(
                fn (array $job): int => ((int) $job['hires'] * 1_000_000)
                    + (int) $job['applications'],
            )
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, JobApplication>  $applications
     * @return list<array{country: string, applications: int, share: float}>
     */
    private function countries(Collection $applications): array
    {
        $total = max(1, $applications->count());

        return array_values($applications
            ->groupBy(fn (JobApplication $application): string => $application
                ->candidateProfile
                ->current_country_code ?: '—')
            ->map(fn (Collection $rows, string $country): array => [
                'country' => $country,
                'applications' => $rows->count(),
                'share' => round($rows->count() / $total * 100, 1),
            ])
            ->sortByDesc('applications')
            ->values()
            ->take(12)
            ->all());
    }

    /**
     * @param  Collection<int, JobApplication>  $applications
     * @return list<array{period: string, label: string, applications: int, interviews: int, hires: int}>
     */
    private function timeline(Collection $applications, Carbon $from, Carbon $to): array
    {
        $monthly = $from->diffInDays($to) > 62;
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();
        $end = $monthly ? $to->copy()->endOfMonth() : $to->copy()->endOfDay();
        $rows = [];

        while ($cursor->lte($end)) {
            $periodStart = $cursor->copy();
            $periodEnd = $monthly ? $cursor->copy()->endOfMonth() : $cursor->copy()->endOfDay();
            $items = $applications->filter(
                fn (JobApplication $application): bool => $application->applied_at->between(
                    $periodStart,
                    $periodEnd,
                ),
            );
            $rows[] = [
                'period' => $periodStart->toDateString(),
                'label' => $monthly
                    ? $periodStart->translatedFormat('M Y')
                    : $periodStart->translatedFormat('d.m.'),
                'applications' => $items->count(),
                'interviews' => $items->filter(
                    fn (JobApplication $application): bool => $application->interviews->isNotEmpty(),
                )->count(),
                'hires' => $items->where('status', ApplicationStatus::Hired)->count(),
            ];
            $cursor = $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return $rows;
    }
}
