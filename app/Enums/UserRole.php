<?php

namespace App\Enums;

enum UserRole: string
{
    case Candidate = 'candidate';
    case Company = 'company';
    case Support = 'support';
    case SuperAdmin = 'super_admin';
}
