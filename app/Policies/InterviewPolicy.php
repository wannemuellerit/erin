<?php

namespace App\Policies;

use App\Models\Interview;
use App\Models\User;

class InterviewPolicy
{
    public function view(User $user, Interview $interview): bool
    {
        return (new JobApplicationPolicy)->view($user, $interview->application);
    }

    public function update(User $user, Interview $interview): bool
    {
        if ($user->isSupport()) {
            return false;
        }

        return $interview->application->candidateProfile->user_id === $user->id
            || (new JobApplicationPolicy)->manage($user, $interview->application);
    }
}
