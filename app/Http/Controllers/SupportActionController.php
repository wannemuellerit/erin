<?php

namespace App\Http\Controllers;

use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Models\Feedback;
use App\Models\JobApplication;
use App\Models\ModerationCase;
use App\Models\SupportTicket;
use App\Notifications\ActivityNotification;
use App\Services\Trust\CompanyTrustMetricService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportActionController extends Controller
{
    public function createTicket(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:80'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'message' => ['required', 'string', 'max:20000'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $companyId = $user->role === UserRole::Company
            ? $user->companies()->value('companies.id')
            : null;
        $ticket = SupportTicket::query()->create([
            'requester_id' => $user->getKey(),
            'company_id' => $companyId,
            'number' => 'ERIN-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'subject' => $validated['subject'],
            'category' => $validated['category'] ?? null,
            'priority' => $validated['priority'],
            'status' => SupportTicketStatus::Open,
            'last_reply_at' => now(),
        ]);
        $ticket->messages()->create([
            'author_id' => $user->getKey(),
            'body' => $validated['message'],
            'is_internal' => false,
        ]);

        return back()->with('success', __('Supportticket :number wurde erstellt.', ['number' => $ticket->number]));
    }

    public function replyTicket(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($ticket->requester_id === $user->getKey() || $user->isPlatformStaff(), 403);
        $validated = $request->validate(['message' => ['required', 'string', 'max:20000']]);
        $ticket->messages()->create([
            'author_id' => $user->getKey(),
            'body' => $validated['message'],
            'is_internal' => false,
        ]);
        $ticket->update([
            'last_reply_at' => now(),
            'status' => $user->isPlatformStaff()
                ? SupportTicketStatus::WaitingForCustomer
                : SupportTicketStatus::Open,
        ]);

        if ($user->isPlatformStaff()) {
            $ticket->requester->notify(new ActivityNotification([
                'event' => 'support.ticket_replied',
                'title' => __('Antwort vom Erin-Support'),
                'message' => __('Dein Ticket :number wurde beantwortet.', ['number' => $ticket->number]),
                'translations' => [
                    'de' => [
                        'title' => 'Antwort vom Erin-Support',
                        'message' => sprintf('Dein Ticket %s wurde beantwortet.', $ticket->number),
                    ],
                    'en' => [
                        'title' => 'Reply from Erin support',
                        'message' => sprintf('Your ticket %s has been answered.', $ticket->number),
                    ],
                ],
                'url' => route('dashboard'),
                'ticket_id' => $ticket->getKey(),
            ]));
        }

        return back()->with('success', __('Antwort wurde gespeichert.'));
    }

    public function feedback(
        Request $request,
        JobApplication $application,
        CompanyTrustMetricService $trustMetrics,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $application->load(['candidateProfile.user', 'jobPosting.company']);
        $isCandidate = $application->candidateProfile->user_id === $user->getKey();
        $isCompany = $user->belongsToCompany($application->jobPosting->company_id);
        if ($isCompany) {
            abort_unless($user->hasCompanyRole($application->jobPosting->company_id, [
                CompanyMemberRole::Owner,
                CompanyMemberRole::Admin,
                CompanyMemberRole::Recruiter,
            ]), 403);
        }
        abort_unless($isCandidate || $isCompany, 403);
        $eligible = $application->interviews()
            ->whereIn('status', [InterviewStatus::Confirmed, InterviewStatus::Completed, InterviewStatus::NoShow])
            ->exists() || in_array($application->status->value, [
                'accepted',
                'visa_in_progress',
                'contract_sent',
                'contract_signed',
                'hired',
            ], true);
        abort_unless($eligible, 422, __('Feedback ist erst nach einem bestätigten Interview oder Vermittlungsschritt möglich.'));
        $validated = $request->validate([
            'sentiment' => ['required', Rule::in(['positive', 'negative'])],
            'reason_code' => ['required', Rule::in([
                'reliable',
                'trustworthy',
                'good_communication',
                'no_show',
                'untrustworthy',
                'contract_not_honored',
            ])],
            'comment' => ['nullable', 'string', 'max:3000'],
            'metrics' => ['array'],
            'metrics.*' => ['boolean'],
        ]);
        $feedback = Feedback::query()->create([
            'author_id' => $user->getKey(),
            'subject_user_id' => $isCompany ? $application->candidateProfile->user_id : null,
            'subject_company_id' => $isCandidate ? $application->jobPosting->company_id : null,
            'application_id' => $application->getKey(),
            'interview_id' => $application->interviews()->latest()->value('id'),
            ...$validated,
            'status' => 'pending',
        ]);

        if ($validated['sentiment'] === 'negative') {
            $negativeCount = Feedback::query()
                ->where('sentiment', 'negative')
                ->when(
                    $feedback->subject_user_id,
                    fn ($query) => $query->where('subject_user_id', $feedback->subject_user_id),
                    fn ($query) => $query->where('subject_company_id', $feedback->subject_company_id),
                )
                ->count();

            if ($negativeCount >= 3) {
                ModerationCase::query()->firstOrCreate(
                    [
                        'subject_user_id' => $feedback->subject_user_id,
                        'subject_company_id' => $feedback->subject_company_id,
                        'status' => 'open',
                    ],
                    [
                        'opened_by' => $user->getKey(),
                        'reason' => 'feedback_threshold',
                        'severity' => $negativeCount >= 5 ? 'high' : 'medium',
                    ],
                );
            }
        }

        if ($feedback->subject_company_id !== null) {
            $trustMetrics->recalculate($application->jobPosting->company);
        }

        return back()->with('success', __('Danke. Das Feedback wird moderiert.'));
    }
}
