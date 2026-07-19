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
use App\Services\Ticketing\SupportAttachmentLimits;
use App\Services\Ticketing\SupportAttachmentManager;
use App\Services\Ticketing\SupportTicketMessagePresenter;
use Illuminate\Database\QueryException;
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
    public function index(
        Request $request,
        SupportTicketMessagePresenter $presenter,
        SupportAttachmentLimits $attachmentLimits,
    ): Response {
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
                    ->with(['author:id,name,role', 'files'])
                    ->oldest(),
            ])
            ->latest('last_reply_at')
            ->latest()
            ->get();
        $tickets = $tickets->map(function (SupportTicket $ticket) use ($presenter): array {
            $serialized = $ticket->toArray();
            $serialized['messages'] = $ticket->messages
                ->map(fn ($message): array => $presenter->present($message))
                ->values()
                ->all();

            return $serialized;
        });
        $requestedTicketId = $request->integer('ticket');

        return Inertia::render('Support', [
            'tickets' => $tickets,
            'selected' => $requestedTicketId > 0 && $tickets->contains('id', $requestedTicketId)
                ? $requestedTicketId
                : ($tickets->first()['id'] ?? null),
            'ticketing' => [
                'provider' => 'zammad',
                'enabled' => (bool) config('services.zammad.enabled'),
            ],
            'attachmentLimits' => $attachmentLimits->forFrontend(),
        ]);
    }

    public function createTicket(
        Request $request,
        ActivityRecorder $activity,
        SupportAttachmentManager $attachmentManager,
    ): RedirectResponse {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:80'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            ...SupportAttachmentManager::validationRules('message'),
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $companyId = $this->activeCompanyId($request);
        [$ticket, $message] = DB::transaction(function () use (
            $user,
            $companyId,
            $validated,
            $request,
            $attachmentManager,
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
                'body' => $validated['message'] ?? '',
                'is_internal' => false,
            ]);
            $files = $request->file('attachments', []);
            $attachmentManager->storeUploads(
                $message,
                is_array($files) ? array_values($files) : [],
                $user->getKey(),
            );

            return [$ticket, $message];
        });
        SupportTicketMessageCreated::dispatch($message->load('files'));
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
        SupportAttachmentManager $attachmentManager,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $companyId = $this->activeCompanyId($request);
        if ($companyId !== null) {
            abort_unless($ticket->company_id === $companyId, 404);
        }
        Gate::authorize('reply', $ticket);
        $validated = $request->validate(SupportAttachmentManager::validationRules('message'));
        $message = DB::transaction(function () use (
            $ticket,
            $user,
            $validated,
            $request,
            $attachmentManager,
        ) {
            $message = $ticket->messages()->create([
                'author_id' => $user->getKey(),
                'body' => $validated['message'] ?? '',
                'is_internal' => false,
            ]);
            $files = $request->file('attachments', []);
            $attachmentManager->storeUploads(
                $message,
                is_array($files) ? array_values($files) : [],
                $user->getKey(),
            );
            $ticket->update([
                'last_reply_at' => now(),
                'status' => $user->isPlatformStaff()
                    ? SupportTicketStatus::WaitingForCustomer
                    : SupportTicketStatus::Open,
            ]);

            return $message;
        });
        SupportTicketMessageCreated::dispatch($message->load('files'));
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
        $subjectType = $isCompany ? 'user' : 'company';
        $subjectKey = $isCompany
            ? $application->candidateProfile->user_id
            : $application->jobPosting->company_id;

        try {
            $feedback = Feedback::query()->create([
                'author_id' => $user->getKey(),
                'subject_user_id' => $isCompany ? $subjectKey : null,
                'subject_company_id' => $isCandidate ? $subjectKey : null,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'application_id' => $application->getKey(),
                'interview_id' => $application->interviews()->latest()->value('id'),
                ...$validated,
                'status' => 'pending',
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return back()->withErrors([
                'feedback' => __('Du hast diese Person oder Firma für diese Bewerbung bereits bewertet.'),
            ]);
        }

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
                $case = ModerationCase::query()->firstOrCreate(
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
                if ($negativeCount >= 5 && $case->severity !== 'high') {
                    $case->update(['severity' => 'high', 'priority' => 'high']);
                }
            }
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
