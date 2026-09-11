<?php

namespace App\Jobs;

use App\Events\MessageSent;
use App\Models\CandidateBulkBatch;
use App\Models\CandidateBulkBatchItem;
use App\Models\Conversation;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Message;
use App\Services\Activity\ActivityRecorder;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ProcessCandidateBulkBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 180];

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(
        ActivityRecorder $activity,
        ProductNotificationDispatcher $notifications,
    ): void {
        /** @var CandidateBulkBatch|null $batch */
        $batch = CandidateBulkBatch::query()->with(['company', 'creator'])->find($this->batchId);
        if ($batch === null || in_array($batch->status, ['completed', 'completed_with_errors', 'cancelled'], true)) {
            return;
        }
        $batch->update(['status' => 'processing']);

        $batch->items()->where('status', 'queued')->orderBy('id')->pluck('id')
            ->each(function (int $itemId) use ($batch, $activity, $notifications): void {
                $batch->refresh();
                if ($batch->cancellation_requested_at !== null) {
                    return;
                }

                /** @var CandidateBulkBatchItem|null $item */
                $item = CandidateBulkBatchItem::query()->with('candidate.user')->find($itemId);
                if ($item?->candidate === null || $item->candidate->published_at === null) {
                    $this->finishItem($batch, $item, 'skipped', 'not_available');

                    return;
                }
                if (
                    $item->candidate_updated_at !== null
                    && ! $item->candidate->updated_at->equalTo($item->candidate_updated_at)
                ) {
                    $this->finishItem($batch, $item, 'skipped', 'concurrent_change');

                    return;
                }

                $batch->action === 'invite'
                    ? $this->invite($batch, $item, $activity, $notifications)
                    : $this->message($batch, $item, $activity, $notifications);
            });

        $batch->refresh();
        if ($batch->cancellation_requested_at !== null) {
            $batch->items()->where('status', 'queued')->update([
                'status' => 'cancelled',
                'reason' => 'batch_cancelled',
                'updated_at' => now(),
            ]);
        }
        $failed = $batch->items()->whereIn('status', ['failed', 'skipped'])->count();
        $succeeded = $batch->items()->where('status', 'completed')->count();
        $batch->update([
            'status' => $batch->cancellation_requested_at !== null
                ? 'cancelled'
                : ($failed > 0 ? 'completed_with_errors' : 'completed'),
            'processed' => $failed + $succeeded,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'completed_at' => now(),
        ]);
    }

    private function invite(
        CandidateBulkBatch $batch,
        CandidateBulkBatchItem $item,
        ActivityRecorder $activity,
        ProductNotificationDispatcher $notifications,
    ): void {
        /** @var JobPosting|null $job */
        $job = JobPosting::query()->published()
            ->where('company_id', $batch->company_id)
            ->find($batch->payload['job_posting_id'] ?? null);
        if ($job === null) {
            $this->finishItem($batch, $item, 'skipped', 'job_unavailable');

            return;
        }

        /** @var JobInvitation $invitation */
        $invitation = JobInvitation::query()->firstOrCreate([
            'job_posting_id' => $job->getKey(),
            'candidate_profile_id' => $item->candidate_profile_id,
        ], [
            'invited_by' => $batch->created_by,
            'status' => 'pending',
            'message' => $batch->payload['message'] ?? null,
            'expires_at' => now()->addDays(14),
        ]);
        if (! $invitation->wasRecentlyCreated) {
            $this->finishItem($batch, $item, 'skipped', 'already_invited', $invitation);

            return;
        }

        $this->finishItem($batch, $item, 'completed', null, $invitation);
        $notifications->dispatch(
            $item->candidate->user,
            'application.created',
            "bulk:{$batch->getKey()}:candidate:{$item->candidate_profile_id}:invite",
            [
                'translations' => [
                    'de' => ['title' => 'Neue Firmeneinladung', 'message' => "Du wurdest zur Stelle „{$job->title}“ eingeladen."],
                    'en' => ['title' => 'New company invitation', 'message' => "You have been invited to the position “{$job->title}”."],
                ],
                'url' => route('candidate.jobs'),
                'invitation_id' => $invitation->getKey(),
            ],
        );
        $activity->record(
            'candidate.invited',
            $batch->creator,
            $batch->company,
            $invitation,
            ['candidate_label' => $item->candidate->anonymizedLabel(), 'job_title' => $job->title],
            $item->candidate->user,
            'shared',
            "bulk:{$batch->getKey()}:item:{$item->getKey()}",
        );
    }

    private function message(
        CandidateBulkBatch $batch,
        CandidateBulkBatchItem $item,
        ActivityRecorder $activity,
        ProductNotificationDispatcher $notifications,
    ): void {
        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()
            ->where('company_id', $batch->company_id)
            ->whereHas('application', fn ($query) => $query->where('candidate_profile_id', $item->candidate_profile_id))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $batch->created_by))
            ->with('participants:id,name,email,locale')
            ->first();
        if ($conversation === null) {
            $this->finishItem($batch, $item, 'skipped', 'conversation_unavailable');

            return;
        }

        $clientId = Uuid::uuid5(Uuid::NAMESPACE_URL, "erin:bulk:{$batch->getKey()}:{$item->getKey()}")->toString();
        /** @var Message $message */
        $message = DB::transaction(function () use ($batch, $conversation, $clientId, $item): Message {
            /** @var Message $message */
            $message = Message::query()->firstOrCreate([
                'sender_id' => $batch->created_by,
                'client_id' => $clientId,
            ], [
                'conversation_id' => $conversation->getKey(),
                'type' => 'text',
                'body' => $batch->payload['message'],
                'metadata' => ['bulk' => true, 'batch_id' => $batch->getKey()],
            ]);
            $conversation->update(['last_message_at' => now()]);
            $this->finishItem($batch, $item, 'completed', null, $message);

            return $message;
        }, 3);

        broadcast(new MessageSent($message))->toOthers();
        foreach ($conversation->participants->where('id', '!=', $batch->created_by) as $participant) {
            $notifications->dispatch(
                $participant,
                'message.received',
                "bulk:{$batch->getKey()}:item:{$item->getKey()}:user:{$participant->getKey()}",
                [
                    'translations' => [
                        'de' => ['title' => 'Neue Nachricht', 'message' => 'Du hast eine neue Recruiting-Nachricht erhalten.'],
                        'en' => ['title' => 'New message', 'message' => 'You received a new recruiting message.'],
                    ],
                    'url' => route('messages.index', ['conversation' => $conversation->getKey()]),
                    'conversation_id' => $conversation->getKey(),
                ],
            );
        }
        $activity->record(
            'candidate.bulk_message_sent',
            $batch->creator,
            $batch->company,
            $message,
            ['conversation_id' => $conversation->getKey()],
            idempotencyKey: "bulk:{$batch->getKey()}:item:{$item->getKey()}",
        );
    }

    private function finishItem(
        CandidateBulkBatch $batch,
        ?CandidateBulkBatchItem $item,
        string $status,
        ?string $reason,
        JobInvitation|Message|null $result = null,
    ): void {
        if ($item === null || $item->status !== 'queued') {
            return;
        }
        $item->update([
            'status' => $status,
            'reason' => $reason,
            'result_type' => $result?->getMorphClass(),
            'result_id' => $result?->getKey(),
        ]);
        $batch->increment('processed');
        $batch->increment($status === 'completed' ? 'succeeded' : 'failed');
    }
}
