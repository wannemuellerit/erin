<?php

namespace App\Services\Trust;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyTrustMetric;
use App\Models\Feedback;
use App\Models\Interview;
use App\Models\JobApplication;
use Illuminate\Support\Facades\DB;

final class CompanyTrustMetricService
{
    private const RESPONSE_WINDOW_DAYS = 7;

    public function recalculate(Company $company): CompanyTrustMetric
    {
        $previous = $company->trustMetric()->first()?->only([
            'response_rate',
            'interview_attendance_rate',
            'contract_compliance_rate',
            'cases_count',
            'is_top_company',
        ]) ?? [];
        $applications = JobApplication::query()
            ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
            ->with(['interviews', 'statusHistory'])
            ->get();

        /** @var array<int, list<Feedback>> $feedbackByApplication */
        $feedbackByApplication = [];
        /** @var array<int, list<Feedback>> $feedbackByInterview */
        $feedbackByInterview = [];

        $feedbacks = Feedback::query()
            ->where('subject_company_id', $company->getKey())
            ->where('status', 'approved')
            ->orderBy('id')
            ->get();

        foreach ($feedbacks as $feedback) {
            if ($feedback->application_id !== null) {
                $feedbackByApplication[$feedback->application_id][] = $feedback;
            }

            if ($feedback->interview_id !== null) {
                $feedbackByInterview[$feedback->interview_id][] = $feedback;
            }
        }

        /** @var array<int, true> $caseApplicationIds */
        $caseApplicationIds = [];
        /** @var list<bool> $responseOutcomes */
        $responseOutcomes = [];
        /** @var list<bool> $interviewOutcomes */
        $interviewOutcomes = [];
        /** @var list<bool> $contractOutcomes */
        $contractOutcomes = [];

        foreach ($applications as $application) {
            $applicationId = $application->getKey();
            $applicationFeedback = $feedbackByApplication[$applicationId] ?? [];
            $hasOutcome = false;

            $responseOutcome = $this->responseOutcome($application, $applicationFeedback);
            if ($responseOutcome !== null) {
                $responseOutcomes[] = $responseOutcome;
                $hasOutcome = true;
            }

            foreach ($application->interviews as $interview) {
                $interviewOutcome = $this->interviewOutcome(
                    $interview,
                    $feedbackByInterview[$interview->getKey()] ?? [],
                );

                if ($interviewOutcome !== null) {
                    $interviewOutcomes[] = $interviewOutcome;
                    $hasOutcome = true;
                }
            }

            $contractOutcome = $this->contractOutcome($application, $applicationFeedback);
            if ($contractOutcome !== null) {
                $contractOutcomes[] = $contractOutcome;
                $hasOutcome = true;
            }

            if ($applicationFeedback !== []) {
                $hasOutcome = true;
            }

            if ($hasOutcome) {
                $caseApplicationIds[$applicationId] = true;
            }
        }

        $casesCount = count($caseApplicationIds);
        $isPublic = $casesCount >= CompanyTrustMetric::MIN_PUBLIC_CASES;
        $metric = CompanyTrustMetric::query()->updateOrCreate(
            ['company_id' => $company->getKey()],
            [
                'response_rate' => $isPublic ? $this->rate($responseOutcomes) : null,
                'interview_attendance_rate' => $isPublic ? $this->rate($interviewOutcomes) : null,
                'contract_compliance_rate' => $isPublic ? $this->rate($contractOutcomes) : null,
                'cases_count' => $casesCount,
                'calculated_at' => now(),
            ],
        );

        $this->recalculateTopCompanies();

        $metric->refresh();
        AuditLog::query()->create([
            'company_id' => $company->getKey(),
            'event' => 'company.trust.recalculated',
            'auditable_type' => $company->getMorphClass(),
            'auditable_id' => $company->getKey(),
            'before_values' => $previous ?: null,
            'after_values' => $metric->only([
                'response_rate',
                'interview_attendance_rate',
                'contract_compliance_rate',
                'cases_count',
                'is_top_company',
            ]),
            'metadata' => ['calculator_version' => 1],
            'created_at' => now(),
        ]);

        return $metric;
    }

    public function recalculateTopCompanies(): void
    {
        $eligible = CompanyTrustMetric::query()
            ->where('cases_count', '>=', CompanyTrustMetric::MIN_PUBLIC_CASES)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('response_rate')
                    ->orWhereNotNull('interview_attendance_rate')
                    ->orWhereNotNull('contract_compliance_rate');
            })
            ->get()
            ->map(function (CompanyTrustMetric $metric): array {
                $rates = array_values(array_filter([
                    $metric->response_rate,
                    $metric->interview_attendance_rate,
                    $metric->contract_compliance_rate,
                ], static fn (?string $rate): bool => $rate !== null));

                return [
                    'id' => $metric->getKey(),
                    'company_id' => $metric->company_id,
                    'cases_count' => $metric->cases_count,
                    'score' => array_sum(array_map(
                        static fn (string $rate): float => (float) $rate,
                        $rates,
                    )) / count($rates),
                ];
            })
            ->sort(static function (array $left, array $right): int {
                $scoreOrder = $right['score'] <=> $left['score'];

                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }

                $caseOrder = $right['cases_count'] <=> $left['cases_count'];

                return $caseOrder !== 0
                    ? $caseOrder
                    : $left['company_id'] <=> $right['company_id'];
            })
            ->values();

        $topCount = $eligible->isEmpty() ? 0 : max(1, (int) ceil($eligible->count() * 0.05));
        $topMetricIds = $eligible->take($topCount)->pluck('id')->all();

        DB::transaction(function () use ($topMetricIds): void {
            CompanyTrustMetric::query()
                ->where('is_top_company', true)
                ->update(['is_top_company' => false]);

            if ($topMetricIds !== []) {
                CompanyTrustMetric::query()
                    ->whereKey($topMetricIds)
                    ->update(['is_top_company' => true]);
            }
        });
    }

    /**
     * @param  list<Feedback>  $feedback
     */
    private function responseOutcome(JobApplication $application, array $feedback): ?bool
    {
        $explicitOutcome = $this->lastMetric($feedback, [
            'response_received',
            'responded',
            'company_responded',
        ]);

        if ($explicitOutcome !== null) {
            return $explicitOutcome;
        }

        if ($feedback !== []) {
            return true;
        }

        if (! in_array($application->status, [ApplicationStatus::New, ApplicationStatus::Withdrawn], true)) {
            return true;
        }

        if ($application->status === ApplicationStatus::Withdrawn) {
            $previouslyHandled = $application->statusHistory->contains(
                fn ($history): bool => ! in_array($history->to_status, [
                    ApplicationStatus::New,
                    ApplicationStatus::Withdrawn,
                ], true),
            );

            return $previouslyHandled ? true : null;
        }

        return $application->applied_at->lte(now()->subDays(self::RESPONSE_WINDOW_DAYS))
            ? false
            : null;
    }

    /**
     * @param  list<Feedback>  $feedback
     */
    private function interviewOutcome(Interview $interview, array $feedback): ?bool
    {
        $explicitOutcome = $this->lastMetric($feedback, [
            'interview_attended',
            'company_attended',
        ]);

        if ($explicitOutcome !== null) {
            return $explicitOutcome;
        }

        foreach (array_reverse($feedback) as $entry) {
            if ($entry->reason_code === 'no_show') {
                return false;
            }
        }

        if (is_bool($interview->metadata['company_attended'] ?? null)) {
            return $interview->metadata['company_attended'];
        }

        return $interview->status === InterviewStatus::Completed ? true : null;
    }

    /**
     * @param  list<Feedback>  $feedback
     */
    private function contractOutcome(JobApplication $application, array $feedback): ?bool
    {
        $explicitOutcome = $this->lastMetric($feedback, [
            'contract_honored',
            'contract_complied',
        ]);

        if ($explicitOutcome !== null) {
            return $explicitOutcome;
        }

        foreach (array_reverse($feedback) as $entry) {
            if ($entry->reason_code === 'contract_not_honored') {
                return false;
            }
        }

        return in_array($application->status, [
            ApplicationStatus::ContractSigned,
            ApplicationStatus::Hired,
        ], true) ? true : null;
    }

    /**
     * @param  list<Feedback>  $feedback
     * @param  list<string>  $keys
     */
    private function lastMetric(array $feedback, array $keys): ?bool
    {
        foreach (array_reverse($feedback) as $entry) {
            foreach ($keys as $key) {
                $value = $entry->metrics[$key] ?? null;

                if (is_bool($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<bool>  $outcomes
     */
    private function rate(array $outcomes): ?float
    {
        if ($outcomes === []) {
            return null;
        }

        return round(
            count(array_filter($outcomes)) / count($outcomes) * 100,
            2,
        );
    }
}
