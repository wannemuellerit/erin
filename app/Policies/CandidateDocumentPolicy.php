<?php

namespace App\Policies;

use App\Models\CandidateDocument;
use App\Models\User;
use App\Services\Documents\CandidateDocumentAccess;

class CandidateDocumentPolicy
{
    public function view(User $user, CandidateDocument $candidateDocument): bool
    {
        return app(CandidateDocumentAccess::class)->canDownload($user, $candidateDocument);
    }

    public function update(User $user, CandidateDocument $candidateDocument): bool
    {
        return $candidateDocument->candidateProfile->user_id === $user->id
            || $user->isSuperAdmin();
    }

    public function verify(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
