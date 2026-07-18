<?php

namespace App\Policies;

use App\Models\RecruiterReminder;
use App\Models\User;

class RecruiterReminderPolicy
{
    public function update(User $user, RecruiterReminder $reminder): bool
    {
        $membership = $user->companyMemberships()
            ->where('company_id', $reminder->company_id)
            ->whereNotNull('accepted_at')
            ->first();

        if ($membership === null || ! $membership->role->canRecruit()) {
            return false;
        }

        if (in_array($user->getKey(), [$reminder->creator_id, $reminder->assignee_id], true)) {
            return true;
        }

        return $membership->role->canManage();
    }

    public function delete(User $user, RecruiterReminder $reminder): bool
    {
        return $this->update($user, $reminder);
    }
}
