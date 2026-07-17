<?php

namespace App\Enums;

enum GdprRequestStatus: string
{
    case Requested = 'requested';
    case Verified = 'verified';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
