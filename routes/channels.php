<?php

use App\Models\Conversation;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function (User $user, int $id): bool {
    return $user->getKey() === $id;
});

Broadcast::channel('company.{companyId}', function (User $user, int $companyId): bool {
    return $user->isPlatformStaff() || $user->belongsToCompany($companyId);
});

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId): bool {
    return Conversation::query()
        ->whereKey($conversationId)
        ->whereHas('participants', fn ($query) => $query->where('users.id', $user->getKey()))
        ->exists();
});

Broadcast::channel('application.{applicationId}', function (User $user, int $applicationId): bool {
    $application = JobApplication::query()
        ->with(['candidateProfile:id,user_id', 'jobPosting:id,company_id'])
        ->find($applicationId);

    return $application !== null && (
        $user->isPlatformStaff()
        || $application->candidateProfile->user_id === $user->getKey()
        || $user->belongsToCompany($application->jobPosting->company_id)
    );
});

Broadcast::channel('interview.{interviewId}', function (User $user, int $interviewId): bool {
    $interview = Interview::query()
        ->with(['application.candidateProfile:id,user_id', 'application.jobPosting:id,company_id'])
        ->find($interviewId);

    return $interview !== null && (
        $user->isPlatformStaff()
        || $interview->application->candidateProfile->user_id === $user->getKey()
        || $user->belongsToCompany($interview->application->jobPosting->company_id)
    );
});
