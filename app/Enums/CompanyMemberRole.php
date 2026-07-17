<?php

namespace App\Enums;

enum CompanyMemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Recruiter = 'recruiter';
    case Viewer = 'viewer';

    public function canManage(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canRecruit(): bool
    {
        return $this !== self::Viewer;
    }
}
