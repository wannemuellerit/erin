<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        /** @var User $user */
        $user = $request->user();
        $term = trim($data['q']);

        return response()->json([
            'query' => $term,
            'minimum_characters' => 2,
            'groups' => match ($user->role) {
                UserRole::Candidate => $this->candidateResults($user, $term),
                UserRole::Company => $this->companyResults($request, $user, $term),
                UserRole::Partner => [],
                UserRole::Support, UserRole::SuperAdmin => $this->staffResults($term),
            },
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function candidateResults(User $user, string $term): array
    {
        $profileId = $user->candidateProfile?->getKey();

        return [
            'jobs' => JobPosting::query()->published()->with('company')->where('title', 'like', "%{$term}%")
                ->limit(6)->get()->map(fn (JobPosting $job): array => $this->result(
                    'job', $job->title, $job->company->name, route('candidate.jobs.show', $job),
                ))->values()->all(),
            'applications' => $profileId === null ? [] : JobApplication::query()
                ->where('candidate_profile_id', $profileId)
                ->whereHas('jobPosting', fn ($query) => $query->where('title', 'like', "%{$term}%"))
                ->with('jobPosting.company')->limit(6)->get()
                ->map(fn (JobApplication $application): array => $this->result(
                    'application', $application->jobPosting->title, $application->jobPosting->company->name,
                    route('candidate.applications'),
                ))->values()->all(),
            'companies' => Company::query()->where('name', 'like', "%{$term}%")
                ->where('status', 'active')->limit(6)->get()
                ->map(fn (Company $company): array => $this->result(
                    'company', $company->name, '', route('candidate.companies.show', $company),
                ))->values()->all(),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function companyResults(Request $request, User $user, string $term): array
    {
        $companyId = (int) ($request->session()->get('active_company_id')
            ?? $user->companyMemberships()->whereNotNull('accepted_at')->value('company_id'));
        abort_unless($companyId > 0 && $user->belongsToCompany($companyId), 403);

        return [
            'candidates' => CandidateProfile::query()->published()
                ->where(function ($query) use ($term): void {
                    $query->where('desired_position', 'like', "%{$term}%")
                        ->orWhere('current_position', 'like', "%{$term}%");
                })->limit(6)->get()
                ->map(fn (CandidateProfile $profile): array => $this->result(
                    'candidate', $profile->anonymizedLabel(), '', route('employer.candidates.show', $profile),
                ))->values()->all(),
            'jobs' => JobPosting::query()->where('company_id', $companyId)
                ->where('title', 'like', "%{$term}%")->limit(6)->get()
                ->map(fn (JobPosting $job): array => $this->result(
                    'job', $job->title, $job->status->value, route('employer.jobs.edit', $job),
                ))->values()->all(),
            'applications' => JobApplication::query()
                ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $companyId)
                    ->where('title', 'like', "%{$term}%"))
                ->with(['jobPosting', 'candidateProfile'])->limit(6)->get()
                ->map(fn (JobApplication $application): array => $this->result(
                    'application', $application->jobPosting->title,
                    $application->candidateProfile->anonymizedLabel(),
                    route('employer.pipeline', ['job' => $application->job_posting_id]),
                ))->values()->all(),
            'conversations' => Conversation::query()->where('company_id', $companyId)
                ->whereHas('participants', fn ($query) => $query->where('users.id', $user->getKey()))
                ->whereHas('application.jobPosting', fn ($query) => $query->where('title', 'like', "%{$term}%"))
                ->with('application.jobPosting')->limit(6)->get()
                ->map(fn (Conversation $conversation): array => $this->result(
                    'conversation', $conversation->application?->jobPosting->title ?? __('Gespräch'), '',
                    route('messages.index', ['conversation' => $conversation]),
                ))->values()->all(),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function staffResults(string $term): array
    {
        return [
            'users' => User::query()->where(function ($query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            })->limit(6)->get()->map(fn (User $user): array => $this->result(
                'user', $user->name, $user->role->value, route('admin.users.index', ['search' => $term]),
            ))->values()->all(),
            'companies' => Company::query()->where('name', 'like', "%{$term}%")->limit(6)->get()
                ->map(fn (Company $company): array => $this->result(
                    'company', $company->name, (string) $company->status->value,
                    route('admin.companies.index', ['search' => $company->name]),
                ))->values()->all(),
        ];
    }

    /** @return array{type: string, label: string, subtitle: string, url: string} */
    private function result(string $type, string $label, string $subtitle, string $url): array
    {
        return compact('type', 'label', 'subtitle', 'url');
    }
}
