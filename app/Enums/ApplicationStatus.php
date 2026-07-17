<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case InterviewScheduled = 'interview_scheduled';
    case InterviewCompleted = 'interview_completed';
    case DocumentsMissing = 'documents_missing';
    case FinalSelection = 'final_selection';
    case Accepted = 'accepted';
    case VisaInProgress = 'visa_in_progress';
    case ContractSent = 'contract_sent';
    case ContractSigned = 'contract_signed';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function pipelineStage(): string
    {
        return match ($this) {
            self::New => 'new',
            self::InReview, self::DocumentsMissing => 'interesting',
            self::InterviewScheduled, self::InterviewCompleted => 'interview',
            self::FinalSelection => 'final_selection',
            self::Accepted, self::VisaInProgress, self::ContractSent, self::ContractSigned => 'accepted',
            self::Hired => 'hired',
            self::Rejected, self::Withdrawn => 'closed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Hired, self::Rejected, self::Withdrawn], true);
    }
}
