<?php

namespace App\Services\Applications;

use App\Enums\ApplicationStatus;
use DomainException;

final class ApplicationWorkflow
{
    /**
     * @var array<string, list<ApplicationStatus>>
     */
    private const TRANSITIONS = [
        'new' => [
            ApplicationStatus::InReview,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'in_review' => [
            ApplicationStatus::DocumentsMissing,
            ApplicationStatus::InterviewScheduled,
            ApplicationStatus::FinalSelection,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'documents_missing' => [
            ApplicationStatus::InReview,
            ApplicationStatus::InterviewScheduled,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'interview_scheduled' => [
            ApplicationStatus::InterviewCompleted,
            ApplicationStatus::InReview,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'interview_completed' => [
            ApplicationStatus::FinalSelection,
            ApplicationStatus::InterviewScheduled,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'final_selection' => [
            ApplicationStatus::Accepted,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'accepted' => [
            ApplicationStatus::ContractSent,
            ApplicationStatus::VisaInProgress,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'visa_in_progress' => [
            ApplicationStatus::ContractSent,
            ApplicationStatus::ContractSigned,
            ApplicationStatus::Hired,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'contract_sent' => [
            ApplicationStatus::ContractSigned,
            ApplicationStatus::Rejected,
            ApplicationStatus::Withdrawn,
        ],
        'contract_signed' => [
            ApplicationStatus::VisaInProgress,
            ApplicationStatus::Hired,
            ApplicationStatus::Withdrawn,
        ],
    ];

    public function canTransition(ApplicationStatus $from, ApplicationStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertCanTransition(ApplicationStatus $from, ApplicationStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new DomainException(
                "Der Bewerbungsstatus kann nicht von {$from->value} auf {$to->value} geändert werden.",
            );
        }
    }
}
