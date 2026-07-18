<?php

namespace App\Http\Controllers\Employer;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\CandidateProfile;
use App\Models\Conversation;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Message;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Companies\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkCandidateController extends Controller
{
    public function invite(
        Request $request,
        CurrentCompany $currentCompany,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'candidate_ids' => ['required', 'array', 'min:1', 'max:100'],
            'candidate_ids.*' => ['required', 'integer', 'distinct'],
            'job_posting_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:3000'],
        ]);
        /** @var JobPosting $job */
        $job = $company->jobPostings()->published()->findOrFail($validated['job_posting_id']);
        $candidates = CandidateProfile::query()
            ->published()
            ->with('user:id,name,email,locale')
            ->whereKey($validated['candidate_ids'])
            ->get();
        abort_unless($candidates->count() === count($validated['candidate_ids']), 422);
        $user = $request->user();
        abort_if($user === null, 401);
        $created = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $invitation = JobInvitation::query()
                ->where('job_posting_id', $job->getKey())
                ->where('candidate_profile_id', $candidate->getKey())
                ->first();

            if ($invitation?->status === 'accepted') {
                $skipped++;

                continue;
            }

            /** @var JobInvitation $invitation */
            $invitation = JobInvitation::query()->updateOrCreate(
                [
                    'job_posting_id' => $job->getKey(),
                    'candidate_profile_id' => $candidate->getKey(),
                ],
                [
                    'invited_by' => $user->getKey(),
                    'status' => 'pending',
                    'message' => $validated['message'] ?? null,
                    'expires_at' => now()->addDays(14),
                    'responded_at' => null,
                ],
            );

            $candidate->user->notify(new ActivityNotification([
                'event' => 'application.invited',
                'translations' => [
                    'de' => [
                        'title' => 'Neue Firmeneinladung',
                        'message' => sprintf('Du wurdest zur Stelle „%s“ eingeladen.', $job->title),
                    ],
                    'en' => [
                        'title' => 'New company invitation',
                        'message' => sprintf('You have been invited to the position “%s”.', $job->title),
                    ],
                ],
                'url' => route('candidate.jobs'),
                'invitation_id' => $invitation->getKey(),
            ]));
            $activity->record(
                'candidate.invited',
                $user,
                $company,
                $invitation,
                [
                    'candidate_label' => $candidate->anonymizedLabel(),
                    'job_title' => $job->title,
                ],
                $candidate->user,
                'shared',
            );
            $created++;
        }

        return back()->with(
            'success',
            __(':created Einladung(en) versendet, :skipped übersprungen.', [
                'created' => $created,
                'skipped' => $skipped,
            ]),
        );
    }

    public function message(
        Request $request,
        CurrentCompany $currentCompany,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'candidate_ids' => ['required', 'array', 'min:1', 'max:100'],
            'candidate_ids.*' => ['required', 'integer', 'distinct'],
            'message' => ['required', 'string', 'max:10000'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);

        /** @var Collection<int, Conversation> $conversations */
        $conversations = Conversation::query()
            ->where('company_id', $company->getKey())
            ->whereHas(
                'application',
                fn ($query) => $query->whereIn('candidate_profile_id', $validated['candidate_ids']),
            )
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->getKey()))
            ->with(['participants:id,name,email,locale', 'application.candidateProfile:id,user_id'])
            ->get()
            ->unique(fn (Conversation $conversation): int => $conversation->application->candidate_profile_id)
            ->values();

        $sent = 0;
        foreach ($conversations as $conversation) {
            /** @var Message $message */
            $message = DB::transaction(function () use ($conversation, $user, $validated): Message {
                $message = $conversation->messages()->create([
                    'sender_id' => $user->getKey(),
                    'type' => 'text',
                    'body' => $validated['message'],
                    'metadata' => ['bulk' => true],
                ]);
                $conversation->update(['last_message_at' => now()]);
                $conversation->participantRecords()
                    ->where('user_id', $user->getKey())
                    ->update(['last_read_at' => now()]);

                return $message;
            });

            broadcast(new MessageSent($message))->toOthers();
            foreach ($conversation->participants->where('id', '!=', $user->getKey()) as $participant) {
                $participant->notify(new ActivityNotification([
                    'event' => 'message.received',
                    'translations' => [
                        'de' => [
                            'title' => 'Neue Nachricht',
                            'message' => sprintf('Du hast eine neue Nachricht von %s erhalten.', $user->name),
                        ],
                        'en' => [
                            'title' => 'New message',
                            'message' => sprintf('You received a new message from %s.', $user->name),
                        ],
                    ],
                    'url' => route('messages.index', ['conversation' => $conversation->getKey()]),
                    'conversation_id' => $conversation->getKey(),
                ]));
            }

            $activity->record(
                'candidate.bulk_message_sent',
                $user,
                $company,
                $message,
                ['conversation_id' => $conversation->getKey()],
            );
            $sent++;
        }

        return back()->with(
            $sent > 0 ? 'success' : 'warning',
            __(':sent Nachricht(en) gesendet. Für :skipped Fachkraft/Fachkräfte besteht noch keine freigegebene Unterhaltung.', [
                'sent' => $sent,
                'skipped' => count($validated['candidate_ids']) - $sent,
            ]),
        );
    }
}
