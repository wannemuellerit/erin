<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Events\SupportTicketMessageCreated;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\SupportChatMessage;
use App\Models\SupportChatSession;
use App\Models\SupportKnowledgeArticle;
use App\Models\SupportTicket;
use App\Services\Activity\ActivityRecorder;
use App\Services\SupportChat\SupportChatResponder;
use App\Services\SupportChat\SupportChatSafety;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportChatController extends Controller
{
    public function store(Request $request, ActivityRecorder $activity): JsonResponse
    {
        abort_unless((bool) config('support.chatbot.enabled', true), 404);
        $validated = $request->validate([
            'locale' => ['nullable', Rule::in(config('app.supported_locales', ['de', 'en']))],
            'current_route' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $retention = now()->addDays(max(1, (int) config('support.chatbot.retention_days', 30)));
        $session = SupportChatSession::query()->create([
            'user_id' => $user->getKey(),
            'company_id' => $this->activeCompanyId($request),
            'locale' => $validated['locale'] ?? $user->locale ?? 'de',
            'status' => 'active',
            'current_route' => $validated['current_route'] ?? null,
            'last_activity_at' => now(),
            'retention_expires_at' => $retention,
        ]);
        $activity->record(
            'support.chat_started',
            $user,
            $session->company_id,
            null,
            ['session_id' => $session->getKey()],
            $user,
            'private',
        );

        return response()->json(['session' => $this->presentSession($session)], 201);
    }

    public function message(
        Request $request,
        SupportChatSession $session,
        SupportChatResponder $responder,
    ): JsonResponse {
        $this->authorizeSession($request, $session);
        abort_unless($session->status === 'active', 409, __('Dieser Supportchat ist bereits beendet.'));
        $validated = $request->validate([
            'client_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:4000'],
            'locale' => ['nullable', Rule::in(config('app.supported_locales', ['de', 'en']))],
            'current_route' => ['nullable', 'string', 'max:500'],
        ]);
        $retention = $session->retention_expires_at;

        try {
            $userMessage = $session->messages()->create([
                'client_id' => $validated['client_id'],
                'author' => 'user',
                'body' => $validated['message'],
                'retention_expires_at' => $retention,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $userMessage = $session->messages()
                ->where('client_id', $validated['client_id'])
                ->where('author', 'user')
                ->firstOrFail();
            $assistant = $session->messages()
                ->where('author', 'assistant')
                ->where('id', '>', $userMessage->getKey())
                ->oldest('id')
                ->first();

            if ($assistant !== null) {
                return response()->json(['message' => $this->presentMessage($assistant)]);
            }
        }

        $startedAt = hrtime(true);
        $user = $request->user();
        $locale = $validated['locale'] ?? $session->locale;
        $answer = $responder->answer(
            $validated['message'],
            $locale,
            $user?->role->value ?? 'candidate',
        );
        $assistant = $session->messages()->create([
            'author' => 'assistant',
            'body' => $answer['body'],
            'source_article_ids' => $answer['source_ids'],
            'escalation_required' => $answer['escalation_required'],
            'latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'provider' => $answer['provider'],
            'prompt_version' => $answer['prompt_version'],
            'retention_expires_at' => $retention,
        ]);
        $session->update([
            'locale' => $locale,
            'current_route' => $validated['current_route'] ?? $session->current_route,
            'last_activity_at' => now(),
        ]);

        return response()->json(['message' => $this->presentMessage($assistant)], 201);
    }

    public function feedback(Request $request, SupportChatSession $session, SupportChatMessage $message): JsonResponse
    {
        $this->authorizeSession($request, $session);
        abort_unless($message->support_chat_session_id === $session->getKey() && $message->author === 'assistant', 404);
        $validated = $request->validate([
            'feedback' => ['required', Rule::in(['helpful', 'unhelpful'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $message->update([
            'feedback' => $validated['feedback'],
            'feedback_comment' => $validated['comment'] ?? null,
        ]);

        return response()->json(['message' => $this->presentMessage($message->fresh())]);
    }

    public function handoff(
        Request $request,
        SupportChatSession $session,
        SupportChatSafety $safety,
        ActivityRecorder $activity,
    ): JsonResponse {
        $this->authorizeSession($request, $session);
        $validated = $request->validate([
            'consent' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'size:36'],
            'diagnostics' => ['nullable', 'array'],
            'diagnostics.browser' => ['nullable', 'string', 'max:180'],
            'diagnostics.error_code' => ['nullable', 'string', 'max:80'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $handoffHash = hash('sha256', $session->getKey().'|'.$validated['idempotency_key']);

        [$ticket, $created] = DB::transaction(function () use ($session, $user, $validated, $safety, $handoffHash): array {
            /** @var SupportChatSession $locked */
            $locked = SupportChatSession::query()->lockForUpdate()->findOrFail($session->getKey());
            if ($locked->handed_off_ticket_id !== null) {
                return [SupportTicket::query()->findOrFail($locked->handed_off_ticket_id), false];
            }

            $transcript = $locked->messages()->oldest()->get()
                ->map(fn (SupportChatMessage $message): string => sprintf(
                    '%s: %s',
                    $message->author === 'user' ? 'Nutzer' : 'Faden-Assistent',
                    $safety->redact($message->body),
                ))
                ->implode("\n\n");
            /** @var array<string, mixed> $diagnosticValues */
            $diagnosticValues = is_array($validated['diagnostics'] ?? null) ? $validated['diagnostics'] : [];
            $diagnostics = collect($diagnosticValues)
                ->map(fn (mixed $value, string $key): string => $key.': '.$safety->redact((string) $value))
                ->implode("\n");
            $body = "Bestätigte Übergabe aus dem Faden Support-Assistenten.\n";
            $body .= 'Aktuelle Route: '.($locked->current_route ?: 'nicht angegeben')."\n";
            $body .= $diagnostics !== '' ? "Technische Diagnose:\n{$diagnostics}\n" : '';
            $body .= "\nZulässiger, redigierter Verlauf:\n{$transcript}";

            $ticket = SupportTicket::query()->create([
                'requester_id' => $user->getKey(),
                'company_id' => $locked->company_id,
                'number' => 'ERIN-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'subject' => __('Übergabe vom Support-Assistenten'),
                'category' => 'support_chatbot',
                'priority' => 'normal',
                'status' => 'open',
                'last_reply_at' => now(),
            ]);
            $message = $ticket->messages()->create([
                'author_id' => $user->getKey(),
                'body' => $body,
                'is_internal' => false,
            ]);
            $locked->update([
                'status' => 'handed_off',
                'handed_off_ticket_id' => $ticket->getKey(),
                'handoff_key' => $handoffHash,
                'last_activity_at' => now(),
            ]);
            SupportTicketMessageCreated::dispatch($message);

            return [$ticket, true];
        });
        if ($created) {
            SyncSupportTicketToProvider::dispatch($ticket->getKey());
            $activity->record(
                'support.chat_handed_off',
                $user,
                $ticket->company_id,
                $ticket,
                ['chat_session_id' => $session->getKey(), 'ticket_number' => $ticket->number],
                $user,
                'private',
            );
        }

        return response()->json([
            'ticket_id' => $ticket->getKey(),
            'ticket_number' => $ticket->number,
            'url' => route('support.index', ['ticket' => $ticket->getKey()]),
        ], $created ? 201 : 200);
    }

    public function export(Request $request, SupportChatSession $session): StreamedResponse
    {
        $this->authorizeSession($request, $session);
        $payload = [
            'session' => $this->presentSession($session),
            'messages' => $session->messages()->oldest()->get()->map(fn (SupportChatMessage $message): array => $this->presentMessage($message))->all(),
        ];

        return response()->streamDownload(
            static fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'erin-support-chat-'.$session->getKey().'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function destroy(Request $request, SupportChatSession $session, ActivityRecorder $activity): JsonResponse
    {
        $this->authorizeSession($request, $session);
        $user = $request->user();
        $companyId = $session->company_id;
        $sessionId = $session->getKey();
        $session->delete();
        $activity->record(
            'support.chat_deleted',
            $user,
            $companyId,
            null,
            ['session_id' => $sessionId],
            $user,
            'private',
        );

        return response()->json(status: 204);
    }

    private function authorizeSession(Request $request, SupportChatSession $session): void
    {
        abort_unless($request->user()?->getKey() === $session->user_id, 404);
        $activeCompanyId = $this->activeCompanyId($request);
        abort_unless($session->company_id === null || $session->company_id === $activeCompanyId, 404);
    }

    /** @return array<string, mixed> */
    private function presentSession(SupportChatSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'locale' => $session->locale,
            'status' => $session->status,
            'current_route' => $session->current_route,
            'ticket_id' => $session->handed_off_ticket_id,
            'retention_expires_at' => $session->retention_expires_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentMessage(SupportChatMessage $message): array
    {
        $sources = SupportKnowledgeArticle::query()
            ->whereKey($message->source_article_ids ?? [])
            ->get(['id', 'title', 'source_url', 'version', 'expires_at'])
            ->map(fn (SupportKnowledgeArticle $article): array => [
                'id' => $article->getKey(),
                'title' => $article->title,
                'url' => $article->source_url,
                'version' => $article->version,
            ])
            ->all();

        return [
            'id' => $message->getKey(),
            'author' => $message->author,
            'body' => $message->body,
            'sources' => $sources,
            'escalation_required' => $message->escalation_required,
            'feedback' => $message->feedback,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function activeCompanyId(Request $request): ?int
    {
        $user = $request->user();
        abort_if($user === null, 401);
        if ($user->role !== UserRole::Company) {
            return null;
        }

        $companyId = $request->session()->get('active_company_id');
        $membership = $user->companyMemberships()
            ->whereNotNull('accepted_at')
            ->when(is_numeric($companyId), fn ($query) => $query->where('company_id', (int) $companyId))
            ->first();
        abort_if($membership === null, 403, __('Du gehörst keinem aktiven Unternehmen an.'));
        $request->session()->put('active_company_id', $membership->company_id);

        return $membership->company_id;
    }
}
