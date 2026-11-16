<?php

namespace App\Services\Activity;

use App\Models\ActivityEntry;
use App\Models\CandidateImport;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecruiterReminder;
use App\Models\SupportChatSession;
use App\Models\SupportTicket;

class ActivityDeepLinkResolver
{
    public function forCompany(ActivityEntry $entry): ?string
    {
        $subjectId = $entry->subject_id;

        if ($subjectId === null) {
            return null;
        }

        return match ($entry->subject_type) {
            (new JobPosting)->getMorphClass() => route('employer.jobs.edit', $subjectId),
            (new JobApplication)->getMorphClass() => route('employer.pipeline', [
                'application' => $subjectId,
            ]),
            (new RecruiterReminder)->getMorphClass() => null,
            (new CandidateImport)->getMorphClass() => route('employer.candidates.index'),
            (new SupportTicket)->getMorphClass(),
            (new SupportChatSession)->getMorphClass() => route('support.index'),
            default => null,
        };
    }
}
