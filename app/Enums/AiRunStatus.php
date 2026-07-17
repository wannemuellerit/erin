<?php

namespace App\Enums;

enum AiRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
