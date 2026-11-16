<?php

namespace App\Http\Controllers;

use App\Contracts\AiProvider;
use App\Data\AiRequest;
use App\Enums\AiRunStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Events\ConversationRead;
use App\Events\MessageSent;
use App\Events\MessageTranslated;
use App\Jobs\ScanMessageAttachment;
use App\Models\AiConsent;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTranslation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use App\Services\Documents\UploadPolicy;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
                    ->with(['attachments', 'sender:id,name', 'messageTranslations'])
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
            'client_id' => ['nullable', 'uuid'],
            'reply_to_id' => ['nullable', 'integer'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:600', 'required_if:type,voice'],
            'waveform' => ['nullable', 'array', 'max:100'],
            'waveform.*' => ['numeric', 'min:0', 'max:1'],
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

        [$message, $created] = DB::transaction(function () use ($request, $conversation, $user, $validated): array {
            if (isset($validated['client_id'])) {
                $existing = Message::query()
                    ->where('sender_id', $user->getKey())
                    ->where('client_id', $validated['client_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    abort_unless($existing->conversation_id === $conversation->getKey(), 409);

                    return [$existing, false];
                }
            }
            if (isset($validated['reply_to_id'])) {
                abort_unless($conversation->messages()->whereKey($validated['reply_to_id'])->exists(), 422);
            }

            $message = $conversation->messages()->create([
                'sender_id' => $user->getKey(),
                'client_id' => $validated['client_id'] ?? null,
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
                    'duration_seconds' => ($validated['type'] ?? 'text') === 'voice'
                        ? ($validated['duration_seconds'] ?? null)
                        : null,
                    'waveform' => ($validated['type'] ?? 'text') === 'voice'
                        ? ($validated['waveform'] ?? null)
                        : null,
                    'scan_result' => 'pending',
                ]);
                ScanMessageAttachment::dispatch($attachment->getKey());
            }

            $conversation->update(['last_message_at' => now()]);
            $conversation->participantRecords()
                ->where('user_id', $user->getKey())
                ->update(['last_read_at' => now()]);

            return [$message, true];
        });

        if (! $created) {
            return back()->with('success', __('Die Nachricht wurde bereits übermittelt.'));
        }

        broadcast(new MessageSent($message->load(['attachments', 'messageTranslations'])))->toOthers();

        foreach ($conversation->participants()->where('users.id', '!=', $user->getKey())->get() as $participant) {
            app(ProductNotificationDispatcher::class)->dispatch(
                $participant,
                'message.received',
                "message:{$message->getKey()}:received",
                [
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
                ],
            );
        }

        return back();
    }

    public function read(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $readAt = now();
        $updated = $conversation->participantRecords()
            ->where('user_id', $user->getKey())
            ->update(['last_read_at' => $readAt]);
        abort_unless($updated > 0, 403);
        broadcast(new ConversationRead(
            $conversation,
            (int) $user->getKey(),
            $readAt->toIso8601String(),
        ))->toOthers();

        return back();
    }

    public function translate(
        Request $request,
        Message $message,
        AiProvider $provider,
        EntitlementService $entitlements,
    ): JsonResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $message->loadMissing('conversation');
        abort_unless(
            $message->conversation->participants()->where('users.id', $user->getKey())->exists(),
            403,
        );
        $data = $request->validate([
            'target_locale' => ['required', Rule::in(config('app.supported_locales', ['de', 'en']))],
            'explicit_consent' => ['accepted'],
        ]);
        abort_if(blank($message->body), 422, __('Diese Nachricht enthält keinen übersetzbaren Text.'));
        abort_unless(
            $provider->supportsSensitiveDocuments(),
            422,
            __('Nachrichtenübersetzung ist ohne freigegebenen EU-Endpunkt deaktiviert.'),
        );

        $requestKey = hash('sha256', "message:{$message->getKey()}:{$data['target_locale']}");
        [$translation, $run, $created] = DB::transaction(function () use (
            $user,
            $message,
            $data,
            $requestKey,
            $entitlements,
        ): array {
            $existing = MessageTranslation::query()
                ->where('message_id', $message->getKey())
                ->where('target_locale', $data['target_locale'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [$existing, $existing->aiRun, false];
            }

            $consent = AiConsent::query()->create([
                'user_id' => $user->getKey(),
                'purpose' => 'message_translate',
                'version' => '2026-07-19',
                'granted_at' => now(),
                'ip_address' => request()->ip(),
                'data_categories' => ['message_body'],
            ]);
            if ($message->conversation->company_id !== null && $user->role === UserRole::Company) {
                $company = $message->conversation->company()->lockForUpdate()->firstOrFail();
                $entitlements->consumeAiCredits($company);
            } else {
                $used = AiRun::query()->where('user_id', $user->getKey())
                    ->whereNull('company_id')->where('created_at', '>=', now()->startOfMonth())
                    ->whereNot('status', AiRunStatus::Blocked)->count();
                abort_if($used >= 20, 422, __('Deine 20 monatlichen KI-Credits sind aufgebraucht.'));
            }
            $run = AiRun::query()->create([
                'request_key' => $requestKey,
                'user_id' => $user->getKey(),
                'company_id' => $message->conversation->company_id,
                'consent_id' => $consent->getKey(),
                'purpose' => 'message_translate',
                'provider' => 'openai',
                'model' => (string) config('services.openai.economy_model'),
                'prompt_version' => 'erin-message-translate-v1',
                'status' => AiRunStatus::Running,
                'input_manifest' => ['fields' => ['message_body'], 'character_count' => mb_strlen((string) $message->body)],
                'requires_consent' => true,
                'started_at' => now(),
            ]);
            $translation = MessageTranslation::query()->create([
                'message_id' => $message->getKey(),
                'requested_by' => $user->getKey(),
                'ai_run_id' => $run->getKey(),
                'target_locale' => $data['target_locale'],
                'status' => 'processing',
                'provider' => 'openai',
                'prompt_version' => 'erin-message-translate-v1',
            ]);

            return [$translation, $run, true];
        }, 3);

        if (! $created) {
            return response()->json($this->serializeTranslation($translation), $translation->status === 'completed' ? 200 : 409);
        }

        try {
            $response = $provider->respond(new AiRequest(
                task: 'translate',
                instructions: 'Übersetze den Nachrichtentext vollständig in die angegebene Zielsprache. Erhalte Bedeutung und Format. Gib ausschließlich das definierte JSON zurück.',
                input: ['text' => $message->body, 'target_locale' => $data['target_locale']],
                schema: [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['content' => ['type' => 'string']],
                    'required' => ['content'],
                ],
                sensitive: true,
            ));
            $body = $response->result['content'] ?? null;
            abort_unless(is_string($body) && $body !== '', 502);
            $translation->update([
                'status' => 'completed',
                'translated_body' => $body,
                'model' => $response->model,
            ]);
            $run->update([
                'status' => AiRunStatus::Completed,
                'model' => $response->model,
                'output' => ['content' => $body],
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'completed_at' => now(),
            ]);
            broadcast(new MessageTranslated($translation))->toOthers();

            return response()->json($this->serializeTranslation($translation));
        } catch (Throwable $exception) {
            Log::warning('message.translation.failed', ['exception_class' => $exception::class, 'translation_id' => $translation->getKey()]);
            $translation->update(['status' => 'failed', 'error_code' => class_basename($exception)]);
            $run->update(['status' => AiRunStatus::Failed, 'error_message' => 'translation_failed', 'completed_at' => now()]);

            return response()->json(['message' => __('Die Übersetzung ist fehlgeschlagen; die Originalnachricht bleibt unverändert.')], 502);
        }
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
            'client_id' => $message->client_id,
            'sender' => $message->sender?->only(['id', 'name']),
            'sender_id' => $message->sender_id,
            'reply_to_id' => $message->reply_to_id,
            'type' => $message->type,
            'body' => $message->body,
            'translations' => $message->messageTranslations
                ->mapWithKeys(fn ($translation): array => [$translation->target_locale => [
                    'status' => $translation->status,
                    'body' => $translation->translated_body,
                    'model' => $translation->model,
                    'prompt_version' => $translation->prompt_version,
                ]])->all(),
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
            'duration_seconds' => $attachment->duration_seconds,
            'waveform' => $attachment->waveform,
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

    /** @return array<string, mixed> */
    private function serializeTranslation(MessageTranslation $translation): array
    {
        return [
            'message_id' => $translation->message_id,
            'target_locale' => $translation->target_locale,
            'status' => $translation->status,
            'body' => $translation->translated_body,
            'model' => $translation->model,
            'prompt_version' => $translation->prompt_version,
        ];
    }
}
