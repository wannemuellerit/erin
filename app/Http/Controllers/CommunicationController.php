<?php

namespace App\Http\Controllers;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Jobs\ScanMessageAttachment;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Companies\CurrentCompany;
use App\Services\Documents\UploadPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunicationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $conversations = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->getKey()))
            ->with([
                'participants:id,name',
                'application.jobPosting:id,title',
                'messages' => fn ($query) => $query
                    ->with(['attachments', 'sender:id,name'])
                    ->latest()
                    ->limit(40),
            ])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (Conversation $conversation): array => $this->serializeConversation($conversation, $user))
            ->values()
            ->all();

        return Inertia::render(
            $user->role === UserRole::Company ? 'employer/Messages' : 'candidate/Messages',
            ['conversations' => $conversations, 'selected' => $request->integer('conversation') ?: null],
        );
    }

    public function start(
        Request $request,
        JobApplication $application,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $application->load(['candidateProfile.user', 'jobPosting.creator']);

        if ($user->role === UserRole::Company) {
            $company = $currentCompany->forRequest($request);
            abort_unless($application->jobPosting->company_id === $company->getKey(), 404);
            abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
            $otherUser = $application->candidateProfile->user;
        } else {
            abort_unless($application->candidate_profile_id === $user->candidateProfile?->getKey(), 404);
            $otherUser = $application->jobPosting->creator;
        }

        $conversation = Conversation::query()
            ->where('application_id', $application->getKey())
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->getKey()))
            ->first();

        if ($conversation === null) {
            $conversation = DB::transaction(function () use ($application, $user, $otherUser): Conversation {
                $conversation = Conversation::query()->create([
                    'company_id' => $application->jobPosting->company_id,
                    'application_id' => $application->getKey(),
                    'type' => 'application',
                    'title' => $application->jobPosting->title,
                ]);
                $conversation->participants()->attach([
                    $user->getKey() => ['last_read_at' => now()],
                    $otherUser->getKey() => ['last_read_at' => null],
                ]);

                return $conversation;
            });
        }

        return redirect()->route('messages.index', ['conversation' => $conversation->getKey()]);
    }

    public function send(
        Request $request,
        Conversation $conversation,
        UploadPolicy $uploads,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($conversation->participants()->where('users.id', $user->getKey())->exists(), 403);
        if ($user->role === UserRole::Company) {
            abort_unless(
                $conversation->company_id !== null && $user->hasCompanyRole(
                    $conversation->company_id,
                    [
                        CompanyMemberRole::Owner,
                        CompanyMemberRole::Admin,
                        CompanyMemberRole::Recruiter,
                    ],
                ),
                403,
            );
        }
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:20000', 'required_without:attachments'],
            'type' => ['nullable', 'in:text,voice'],
            'reply_to_id' => ['nullable', 'integer'],
            'attachments' => ['array', 'max:8', 'required_without:body'],
            'attachments.*' => [
                'file',
                'mimes:jpg,jpeg,png,gif,pdf,doc,docx,mp3,m4a,ogg,wav,webm',
                'max:'.$uploads->maxFileKilobytes(20480),
            ],
        ]);
        $files = $request->file('attachments', []);
        if (is_array($files) && $files !== []) {
            $uploads->assertCanStore($user, array_values($files), 'attachments');
        }

        $message = DB::transaction(function () use ($request, $conversation, $user, $validated): Message {
            if (isset($validated['reply_to_id'])) {
                abort_unless($conversation->messages()->whereKey($validated['reply_to_id'])->exists(), 422);
            }

            $message = $conversation->messages()->create([
                'sender_id' => $user->getKey(),
                'reply_to_id' => $validated['reply_to_id'] ?? null,
                'type' => $validated['type'] ?? 'text',
                'body' => $validated['body'] ?? null,
            ]);

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store("conversations/{$conversation->getKey()}", 'private');
                abort_if($path === false, 500);
                $attachment = $message->attachments()->create([
                    'disk' => 'private',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'scan_result' => 'pending',
                ]);
                ScanMessageAttachment::dispatch($attachment->getKey());
            }

            $conversation->update(['last_message_at' => now()]);
            $conversation->participantRecords()
                ->where('user_id', $user->getKey())
                ->update(['last_read_at' => now()]);

            return $message;
        });

        broadcast(new MessageSent($message->load('attachments')))->toOthers();

        foreach ($conversation->participants()->where('users.id', '!=', $user->getKey())->get() as $participant) {
            $participant->notify(new ActivityNotification([
                'event' => 'message.received',
                'title' => __('Neue Nachricht'),
                'message' => __('Du hast eine neue Nachricht von :name erhalten.', ['name' => $user->name]),
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

        return back();
    }

    public function read(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $updated = $conversation->participantRecords()
            ->where('user_id', $user->getKey())
            ->update(['last_read_at' => now()]);
        abort_unless($updated > 0, 403);

        return back();
    }

    public function downloadAttachment(
        Request $request,
        MessageAttachment $attachment,
        AuditLogger $audit,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $attachment->loadMissing('message.conversation');
        $conversation = $attachment->message->conversation;
        abort_unless(
            $conversation->participants()->where('users.id', $user->getKey())->exists(),
            403,
        );
        abort_unless(
            $attachment->scan_result === 'clean',
            423,
            __('Der Anhang ist noch nicht sicherheitsgeprüft.'),
        );
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $audit->record('message.attachment_downloaded', $attachment, metadata: [
            'conversation_id' => $conversation->getKey(),
        ], companyId: $conversation->company_id);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(Conversation $conversation, User $user): array
    {
        $participant = $conversation->participantRecords->firstWhere('user_id', $user->getKey());

        return [
            'id' => $conversation->getKey(),
            'title' => $conversation->title ?: $conversation->application?->jobPosting?->title,
            'participants' => $conversation->participants
                ->map(fn (User $participant): array => $participant->only(['id', 'name']))
                ->values()
                ->all(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread' => $conversation->messages()
                ->when(
                    $participant?->last_read_at,
                    fn ($query, $lastRead) => $query->where('created_at', '>', $lastRead),
                )
                ->where('sender_id', '!=', $user->getKey())
                ->count(),
            'messages' => $conversation->messages
                ->reverse()
                ->values()
                ->map(fn (Message $message): array => $this->serializeMessage($message))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(Message $message): array
    {
        return [
            'id' => $message->getKey(),
            'sender' => $message->sender?->only(['id', 'name']),
            'sender_id' => $message->sender_id,
            'reply_to_id' => $message->reply_to_id,
            'type' => $message->type,
            'body' => $message->body,
            'translations' => $message->translations,
            'created_at' => $message->created_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'attachments' => $message->attachments
                ->map(fn (MessageAttachment $attachment): array => $this->serializeAttachment($attachment))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(MessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->getKey(),
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'scan_result' => $attachment->scan_result,
            'download_url' => $attachment->scan_result === 'clean'
                ? URL::temporarySignedRoute(
                    'messages.attachments.download',
                    now()->addMinutes(15),
                    ['attachment' => $attachment],
                )
                : null,
        ];
    }
}
