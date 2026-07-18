<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $supportTicket): bool
    {
        return $supportTicket->requester_id === $user->id
            || $user->isPlatformStaff()
            || ($supportTicket->company_id !== null && $user->belongsToCompany($supportTicket->company_id));
    }

    public function reply(User $user, SupportTicket $supportTicket): bool
    {
        return $supportTicket->requester_id === $user->id
            || $user->isPlatformStaff()
            || ($supportTicket->company_id !== null && $user->belongsToCompany($supportTicket->company_id));
    }

    public function assign(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isSupport();
    }
}
