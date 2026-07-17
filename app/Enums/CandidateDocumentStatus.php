<?php

namespace App\Enums;

enum CandidateDocumentStatus: string
{
    case Uploaded = 'uploaded';
    case InReview = 'in_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
