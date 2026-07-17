<?php

namespace App\Enums;

enum InterviewStatus: string
{
    case Proposed = 'proposed';
    case CounterProposed = 'counter_proposed';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
