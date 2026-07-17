<?php

namespace App\Enums;

enum VisaStepStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case NotRequired = 'not_required';
}
