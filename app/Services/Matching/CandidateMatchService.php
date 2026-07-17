<?php

namespace App\Services\Matching;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Models\CandidateProfile;
use App\Models\JobPosting;

class CandidateMatchService
{
    public function __construct(private readonly MatchScoreCalculator $calculator) {}

    /**
     * The factors intentionally exclude nationality, origin, gender, age, and health data.
     *
     * @return array{score: int, factors: array<string, array{score: int, weight: int, contribution: float}>}
     */
    public function for(CandidateProfile $candidate, JobPosting $job): array
    {
        $candidate->loadMissing(['skills', 'languages', 'documents']);
        $job->loadMissing(['skills', 'languages']);

        $requiredSkills = $job->skills;
        $candidateSkillIds = $candidate->skills->pluck('id');
        $skills = $requiredSkills->isEmpty()
            ? 1.0
            : $requiredSkills->whereIn('id', $candidateSkillIds)->count() / $requiredSkills->count();

        $language = $this->languageScore($candidate, $job);
        $expectedExperience = (float) ($job->expected_experience_years ?? 0);
        $experience = $expectedExperience <= 0
            ? 1.0
            : min(1.0, (float) $candidate->experience_years / $expectedExperience);
        $preferences = $candidate->employment_preferences ?? [];
        $employment = $preferences === [] || in_array($job->employment_type, $preferences, true) ? 1.0 : 0.25;
        $availability = $candidate->available_from === null
            ? 0.5
            : ($candidate->available_from->lte(now()->addMonths(2)) ? 1.0 : 0.4);
        $salary = $job->compensation_max_cents === null || $candidate->salary_expectation_cents === null
            ? 0.7
            : ($candidate->salary_expectation_cents <= $job->compensation_max_cents ? 1.0 : 0.25);
        $relocation = $candidate->has_work_permit
            || $candidate->relocation_ready
            || ($candidate->requires_visa && $job->visa_package_available)
            ? 1.0
            : 0.0;
        $verifiedDocuments = $candidate->documents
            ->where('status', CandidateDocumentStatus::Verified)
            ->whereIn('type', [
                CandidateDocumentType::Cv,
                CandidateDocumentType::Qualification,
                CandidateDocumentType::LanguageCertificate,
                CandidateDocumentType::Passport,
            ])
            ->count();

        return $this->calculator->calculate([
            'profession' => $candidate->occupation_id && $candidate->occupation_id === $job->occupation_id ? 1.0 : 0.0,
            'skills' => $skills,
            'language' => $language,
            'experience' => $experience,
            'employment' => $employment,
            'availability' => $availability,
            'salary' => $salary,
            'relocation' => $relocation,
            'documents' => min(1.0, $verifiedDocuments / 3),
        ]);
    }

    private function languageScore(CandidateProfile $candidate, JobPosting $job): float
    {
        if ($job->languages->isEmpty()) {
            return 1.0;
        }

        $levels = ['A1' => 1, 'A2' => 2, 'B1' => 3, 'B2' => 4, 'C1' => 5, 'C2' => 6];
        $scores = [];

        foreach ($job->languages as $required) {
            $actual = $candidate->languages->firstWhere('id', $required->id);
            $requiredLevel = strtoupper((string) $required->pivot->getAttribute('minimum_level'));
            $actualLevel = strtoupper((string) ($actual?->pivot->getAttribute('level') ?? ''));
            $scores[] = $actual && ($levels[$actualLevel] ?? 0) >= ($levels[$requiredLevel] ?? 6)
                ? 1.0
                : ((bool) $required->pivot->getAttribute('is_required') ? 0.0 : 0.5);
        }

        return array_sum($scores) / count($scores);
    }
}
