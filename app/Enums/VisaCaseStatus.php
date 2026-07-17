<?php

namespace App\Enums;

enum VisaCaseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
