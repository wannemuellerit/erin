<?php

namespace App\Http\Controllers;

use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Events\SupportTicketMessageCreated;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\Feedback;
use App\Models\JobApplication;
use App\Models\ModerationCase;
use App\Models\SupportTicket;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Trust\CompanyTrustMetricService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupportActionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $companyId = $this->activeCompanyId($request);
        $tickets = SupportTicket::query()
            ->when(
                $companyId !== null,
                fn ($query) => $query->where('company_id', $companyId),
                fn ($query) => $query->where('requester_id', $user->getKey()),
            )
            ->with([
                'requester:id,name,email',
                'assignee:id,name',
                'messages' => fn ($query) => $query
                    ->where('is_internal', false)
                    ->with('author:id,name,role')
                    ->oldest(),
            ])
            ->latest('last_reply_at')
            ->latest()
            ->get();
        $requestedTicketId = $request->integer('ticket');

        return Inertia::render('Support', [
            'tickets' => $tickets,
            'selected' => $requestedTicketId > 0 && $tickets->contains('id', $requestedTicketId)
                ? $requestedTicketId
                : $tickets->first()?->getKey(),
            'ticketing' => [
                'provider' => 'zammad',
                'enabled' => (bool) config('services.zammad.enabled'),
            ],
        ]);
    }

    public function createTicket(
        Request $request,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:80'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'message' => ['required', 'string', 'max:20000'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $companyId = $this->activeCompanyId($request);
        [$ticket, $message] = DB::transaction(function () use (
            $user,
            $companyId,
            $validated,
        ): array {
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
            $message = $ticket->messages()->create([
                'author_id' => $user->getKey(),
                'body' => $validated['message'],
                'is_internal' => false,
            ]);

            return [$ticket, $message];
        });
        SupportTicketMessageCreated::dispatch($message);
        SyncSupportTicketToProvider::dispatch($ticket->getKey());
        $activity->record(
            'support.ticket_created',
            $user,
            $companyId,
            $ticket,
            ['ticket_number' => $ticket->number, 'subject' => $ticket->subject],
            $user,
            'shared',
        );

        return redirect()->route('support.index', ['ticket' => $ticket->getKey()])
            ->with('success', __('Supportticket :number wurde erstellt.', ['number' => $ticket->number]));
    }

    public function replyTicket(
        Request $request,
        SupportTicket $ticket,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $companyId = $this->activeCompanyId($request);
        if ($companyId !== null) {
            abort_unless($ticket->company_id === $companyId, 404);
        }
        Gate::authorize('reply', $ticket);
        $validated = $request->validate(['message' => ['required', 'string', 'max:20000']]);
        $message = $ticket->messages()->create([
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
        SupportTicketMessageCreated::dispatch($message);
        if ($ticket->external_id === null) {
            Bus::chain([
                new SyncSupportTicketToProvider($ticket->getKey()),
                new SyncSupportMessageToProvider($message->getKey()),
            ])->dispatch();
        } else {
            SyncSupportMessageToProvider::dispatch($message->getKey());
        }
        $activity->record(
            'support.message_sent',
            $user,
            $ticket->company_id,
            $message,
            ['ticket_number' => $ticket->number],
            $ticket->requester,
            'shared',
        );

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
                'url' => route('support.index', ['ticket' => $ticket->getKey()]),
                'ticket_id' => $ticket->getKey(),
            ]));
        } elseif ($ticket->assignee !== null) {
            $ticket->assignee->notify(new ActivityNotification([
                'event' => 'support.customer_replied',
                'translations' => [
                    'de' => [
                        'title' => 'Neue Antwort im Support',
                        'message' => sprintf('%s hat auf Ticket %s geantwortet.', $user->name, $ticket->number),
                    ],
                    'en' => [
                        'title' => 'New support reply',
                        'message' => sprintf('%s replied to ticket %s.', $user->name, $ticket->number),
                    ],
                ],
                'url' => route('admin.support.index', ['search' => $ticket->number]),
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

    private function activeCompanyId(Request $request): ?int
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($user->role !== UserRole::Company) {
            return null;
        }

        $activeCompanyId = $request->session()->get('active_company_id');
        $membership = $user->companyMemberships()
            ->whereNotNull('accepted_at')
            ->when(
                is_numeric($activeCompanyId),
                fn ($query) => $query->where('company_id', (int) $activeCompanyId),
            )
            ->first();

        abort_if(
            $membership === null,
            403,
            __('Du gehörst keinem aktiven Unternehmen an.'),
        );
        $request->session()->put('active_company_id', $membership->company_id);

        return $membership->company_id;
    }
}
