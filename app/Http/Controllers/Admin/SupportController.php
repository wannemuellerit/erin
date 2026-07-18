<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupportTicketStatus;
use App\Events\SupportTicketMessageCreated;
use App\Http\Requests\Admin\ReplyToSupportTicketRequest;
use App\Http\Requests\Admin\UpdateSupportTicketRequest;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\Feedback;
use App\Models\ModerationCase;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Ticketing\SupportAttachmentLimits;
use App\Services\Ticketing\SupportAttachmentManager;
use App\Services\Ticketing\SupportTicketMessagePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends AdminController
{
    public function index(
        Request $request,
        SupportTicketMessagePresenter $presenter,
        SupportAttachmentLimits $attachmentLimits,
    ): Response {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:30'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $tickets = SupportTicket::query()
            ->with([
                'requester:id,name,email,role,status',
                'company:id,name,slug',
                'assignee:id,name,email',
                'messages' => fn ($query) => $query
                    ->with(['author:id,name,role', 'files'])
                    ->oldest(),
            ])
            ->withCount('messages')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhereHas('requester', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(
                isset($filters['status']) && SupportTicketStatus::tryFrom($filters['status']) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['priority'] ?? null,
                fn (Builder $query, string $priority): Builder => $query->where('priority', $priority),
            )
            ->when(
                $filters['assigned_to'] ?? null,
                fn (Builder $query, int $assigneeId): Builder => $query->where('assigned_to', $assigneeId),
            )
            ->orderByRaw(
                "case status when 'open' then 0 when 'in_progress' then 1 when 'waiting_for_customer' then 2 else 3 end",
            )
            ->orderByDesc('last_reply_at')
            ->latest()
            ->paginate(20)
            ->withQueryString();
        $tickets->through(function (SupportTicket $ticket) use ($presenter): array {
            $serialized = $ticket->toArray();
            $serialized['messages'] = $ticket->messages
                ->map(fn ($message): array => $presenter->present($message))
                ->values()
                ->all();

            return $serialized;
        });

        return Inertia::render('admin/Support', [
            'tickets' => $tickets,
            'filters' => $filters,
            'statuses' => array_map(
                static fn (SupportTicketStatus $status): string => $status->value,
                SupportTicketStatus::cases(),
            ),
            'staff' => User::query()
                ->whereIn('role', ['support', 'super_admin'])
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']),
            'moderation' => [
                'open_cases' => ModerationCase::query()->where('status', 'open')->count(),
                'pending_feedback' => Feedback::query()->where('status', 'pending')->count(),
            ],
            'attachmentLimits' => $attachmentLimits->forFrontend(),
        ]);
    }

    public function update(
        UpdateSupportTicketRequest $request,
        SupportTicket $ticket,
    ): RedirectResponse {
        $validated = $request->validated();
        $before = [
            'status' => $this->enumValue($ticket->getAttribute('status')),
            'priority' => $ticket->priority,
            'assigned_to' => $ticket->assigned_to,
            'resolved_at' => $this->auditDate($ticket->getAttribute('resolved_at')),
        ];

        $nextStatus = SupportTicketStatus::from($validated['status']);

        $ticket->update([
            'status' => $nextStatus,
            'priority' => $validated['priority'],
            'assigned_to' => $validated['assigned_to'] ?? null,
            'resolved_at' => in_array(
                $nextStatus,
                [SupportTicketStatus::Resolved, SupportTicketStatus::Closed],
                true,
            ) ? ($ticket->resolved_at ?? now()) : null,
        ]);

        $this->audit(
            $request,
            'admin.support_ticket.updated',
            $ticket,
            $before,
            [
                'status' => $this->enumValue($ticket->getAttribute('status')),
                'priority' => $ticket->priority,
                'assigned_to' => $ticket->assigned_to,
                'resolved_at' => $this->auditDate($ticket->getAttribute('resolved_at')),
            ],
        );

        return back()->with('success', __('Das Supportticket wurde aktualisiert.'));
    }

    public function reply(
        ReplyToSupportTicketRequest $request,
        SupportTicket $ticket,
        SupportAttachmentManager $attachmentManager,
    ): RedirectResponse {
        $validated = $request->validated();
        $message = DB::transaction(function () use (
            $ticket,
            $request,
            $validated,
            $attachmentManager,
        ) {
            $message = $ticket->messages()->create([
                'author_id' => $request->user()?->getKey(),
                'body' => $validated['body'] ?? '',
                'is_internal' => (bool) ($validated['is_internal'] ?? false),
            ]);
            $files = $request->file('attachments', []);
            $attachmentManager->storeUploads(
                $message,
                is_array($files) ? array_values($files) : [],
                $request->user()?->getKey(),
            );
            $ticket->update([
                'assigned_to' => $ticket->assigned_to ?? $request->user()?->getKey(),
                'status' => ($validated['is_internal'] ?? false)
                    ? SupportTicketStatus::InProgress
                    : SupportTicketStatus::WaitingForCustomer,
                'last_reply_at' => now(),
                'resolved_at' => null,
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

        $this->audit(
            $request,
            'admin.support_ticket.replied',
            $ticket,
            metadata: [
                'message_id' => $message->getKey(),
                'is_internal' => (bool) ($validated['is_internal'] ?? false),
            ],
        );

        if (! ($validated['is_internal'] ?? false)) {
            $ticket->requester->notify(new ActivityNotification([
                'event' => 'support.ticket_replied',
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
        }

        return back()->with('success', __('Die Antwort wurde gespeichert.'));
    }
}
