<?php

namespace App\Http\Controllers\Employer;

use App\Models\ActivityEntry;
use App\Models\CandidateImport;
use App\Models\RecruiterReminder;
use App\Services\Companies\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductivityController
{
    public function __invoke(Request $request, CurrentCompany $currentCompany): Response
    {
        $company = $currentCompany->forRequest($request);
        $user = $request->user();
        abort_if($user === null, 401);

        return Inertia::render('employer/Productivity', [
            'company_id' => $company->getKey(),
            'reminders' => RecruiterReminder::query()
                ->where('company_id', $company->getKey())
                ->where(function ($query) use ($user): void {
                    $query->where('assignee_id', $user->getKey())
                        ->orWhere('creator_id', $user->getKey());
                })
                ->with([
                    'assignee:id,name',
                    'candidateProfile:id,current_position,desired_position,current_country_code',
                    'jobPosting:id,title',
                ])
                ->orderByRaw('completed_at is not null')
                ->orderBy('due_at')
                ->limit(50)
                ->get(),
            'members' => $company->memberships()
                ->with('user:id,name')
                ->whereNotNull('accepted_at')
                ->get()
                ->map(fn ($membership): array => [
                    'id' => $membership->user_id,
                    'name' => $membership->user->name,
                    'role' => $membership->role->value,
                ])
                ->values(),
            'jobs' => $company->jobPostings()->latest()->get(['id', 'title']),
            'imports' => CandidateImport::query()
                ->where('company_id', $company->getKey())
                ->with([
                    'creator:id,name',
                    'rows' => fn ($query) => $query
                        ->where('status', 'invalid')
                        ->orderBy('row_number')
                        ->limit(10),
                ])
                ->latest()
                ->limit(12)
                ->get(),
            'activity' => ActivityEntry::query()
                ->forCompany($company->getKey())
                ->with('actor:id,name')
                ->latest('occurred_at')
                ->limit(40)
                ->get(),
            'import_fields' => [
                'first_name',
                'last_name',
                'email',
                'current_position',
                'desired_position',
                'current_country_code',
                'experience_years',
                'language_level',
            ],
        ]);
    }
}
