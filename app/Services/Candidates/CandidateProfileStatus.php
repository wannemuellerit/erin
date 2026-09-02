<?php

namespace App\Services\Candidates;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;

class CandidateProfileStatus
{
    public function __construct(private readonly ProfileCompletenessCalculator $calculator) {}

    /**
     * @return array{percentage: int, completed: list<string>, missing: list<string>, can_apply: bool, required_percentage: int}
     */
    public function calculate(CandidateProfile $profile, bool $persist = true): array
    {
        $profile->loadCount(['experiences', 'skills', 'languages', 'educations']);
        $profile->load('documents');
        $data = [
            ...$profile->toArray(),
            'work_experiences_count' => $profile->experiences_count,
            'skills_count' => $profile->skills_count,
            'languages_count' => $profile->languages_count,
            'educations_count' => $profile->educations_count,
            'has_cv' => $profile->documents->contains(fn (CandidateDocument $document): bool => (
                $document->type === CandidateDocumentType::Cv
                && $document->scan_result === 'clean'
            )),
            'has_verified_certificate' => $profile->documents->contains(fn (CandidateDocument $document): bool => (
                in_array($document->type, [
                    CandidateDocumentType::LanguageCertificate,
                    CandidateDocumentType::Qualification,
                ], true)
                && $document->status === CandidateDocumentStatus::Verified
                && ($document->expires_at === null || $document->expires_at->isFuture())
            )),
        ];
        $result = $this->calculator->calculate($data);

        if ($persist && $profile->completeness !== $result['percentage']) {
            $profile->updateQuietly(['completeness' => $result['percentage']]);
        }

        return $result;
    }
}
