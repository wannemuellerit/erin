<?php

namespace App\Http\Controllers;

use App\Contracts\AiProvider;
use App\Data\AiRequest;
use App\Enums\AiRunStatus;
use App\Enums\UserRole;
use App\Models\AiConsent;
use App\Models\AiRun;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiController extends Controller
{
    /** @var list<string> */
    private const COMPANY_TASKS = [
        'job_create',
        'job_improve',
        'applications_summarize',
        'candidate_compare',
        'interview_questions',
    ];

    /** @var list<string> */
    private const CANDIDATE_TASKS = [
        'cv_improve',
        'cover_letter',
        'profile_improve',
        'translate',
        'interview_training',
    ];

    public function studio(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $used = $user->aiRuns()
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNot('status', AiRunStatus::Blocked)
            ->count();

        return Inertia::render('candidate/AiStudio', [
            'credits' => ['used' => $used, 'limit' => 20, 'remaining' => max(0, 20 - $used)],
            'consents' => $user->aiConsents()
                ->latest()
                ->limit(20)
                ->get(['id', 'purpose', 'data_categories', 'granted_at', 'withdrawn_at']),
            'runs' => $user->aiRuns()
                ->latest()
                ->limit(10)
                ->get([
                    'id',
                    'consent_id',
                    'purpose',
                    'model',
                    'status',
                    'output',
                    'requires_consent',
                    'completed_at',
                    'created_at',
                ]),
            'tasks' => self::CANDIDATE_TASKS,
            'document_ai_enabled' => app(AiProvider::class)->supportsSensitiveDocuments(),
        ]);
    }

    public function run(
        Request $request,
        AiProvider $provider,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
    ): JsonResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $allowed = $user->role === UserRole::Company ? self::COMPANY_TASKS : self::CANDIDATE_TASKS;
        $validated = $request->validate([
            'task' => ['required', Rule::in($allowed)],
            'input' => ['required', 'array', 'max:30'],
            'input.*' => ['nullable'],
            'consent_id' => ['nullable', 'integer', 'exists:ai_consents,id'],
        ]);
        $task = $validated['task'];
        /** @var AiConsent|null $consent */
        $consent = isset($validated['consent_id'])
            ? AiConsent::query()
                ->where('user_id', $user->getKey())
                ->whereNull('withdrawn_at')
                ->findOrFail($validated['consent_id'])
            : null;
        $requiresConsent = in_array($task, ['cv_improve', 'profile_improve'], true);

        if ($requiresConsent && $consent === null) {
            return response()->json([
                'message' => __('Für diese Verarbeitung ist eine aktive, zweckgebundene Einwilligung erforderlich.'),
            ], 422);
        }

        $company = null;
        if ($user->role === UserRole::Company) {
            $company = $currentCompany->forUser($user);
            abort_if($company === null, 403);
            $entitlements->consumeAiCredits($company);
        }

        $prompt = $this->prompt($task);
        $runData = [
            'user_id' => $user->getKey(),
            'company_id' => $company?->getKey(),
            'consent_id' => $consent?->getKey(),
            'purpose' => $task,
            'provider' => 'openai',
            'model' => $this->modelFor($task),
            'prompt_version' => $prompt['version'],
            'status' => AiRunStatus::Running,
            'input_manifest' => [
                'fields' => array_keys($validated['input']),
                'character_count' => mb_strlen(json_encode($validated['input'], JSON_UNESCAPED_UNICODE) ?: ''),
                'contains_documents' => false,
            ],
            'requires_consent' => $requiresConsent,
            'started_at' => now(),
        ];
        $run = $company
            ? AiRun::query()->create($runData)
            : $this->createCandidateRun((int) $user->getKey(), $runData);

        try {
            $response = $provider->respond(new AiRequest(
                task: $task,
                instructions: $prompt['instructions'],
                input: $validated['input'],
                schema: $this->schema($task),
            ));
            $run->update([
                'status' => AiRunStatus::Completed,
                'model' => $response->model,
                'output' => $response->result,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'completed_at' => now(),
            ]);

            return response()->json([
                'run_id' => $run->getKey(),
                'result' => $response->result,
                'model' => $response->model,
                'human_review_required' => true,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => __('Die KI-Anfrage konnte nicht abgeschlossen werden. Es wurde keine automatische Entscheidung getroffen.'),
            ], 502);
        }
    }

    public function grantConsent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['cv_improve', 'profile_improve', 'document_analysis'])],
            'data_categories' => ['required', 'array'],
            'data_categories.*' => ['string', 'max:80'],
        ]);

        AiConsent::query()->create([
            'user_id' => $request->user()?->getKey(),
            'purpose' => $validated['purpose'],
            'version' => '2026-07-17',
            'granted_at' => now(),
            'ip_address' => $request->ip(),
            'data_categories' => $validated['data_categories'],
        ]);

        return back()->with('success', __('Deine KI-Einwilligung wurde protokolliert.'));
    }

    public function withdrawConsent(Request $request, AiConsent $consent): RedirectResponse
    {
        abort_unless($consent->user_id === $request->user()?->getKey(), 404);
        $consent->update(['withdrawn_at' => now()]);

        return back()->with('success', __('Die Einwilligung wurde widerrufen.'));
    }

    /**
     * @param  array<string, mixed>  $runData
     */
    private function createCandidateRun(int $userId, array $runData): AiRun
    {
        return DB::transaction(function () use ($userId, $runData): AiRun {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $used = AiRun::query()
                ->where('user_id', $userId)
                ->whereNull('company_id')
                ->where('created_at', '>=', now()->startOfMonth())
                ->whereNot('status', AiRunStatus::Blocked)
                ->count();
            abort_if($used >= 20, 422, __('Deine 20 monatlichen KI-Credits sind aufgebraucht.'));

            return AiRun::query()->create($runData);
        });
    }

    /**
     * @return array{version: string, instructions: string}
     */
    private function prompt(string $task): array
    {
        $base = 'Du bist Erin, ein Recruiting-Assistent. Antworte ausschließlich im vorgegebenen JSON-Schema. '
            .'Triff niemals Einstellungs-, Ablehnungs- oder Statusentscheidungen. Nutze weder Herkunft, Nationalität, '
            .'Geschlecht, Alter noch Gesundheitsdaten für Bewertungen. Benenne Unsicherheiten transparent.';

        return [
            'version' => 'erin-'.$task.'-v1',
            'instructions' => $base.' Aufgabe: '.match ($task) {
                'job_create' => 'Erstelle aus den Angaben eine sachliche Stellenanzeige.',
                'job_improve' => 'Verbessere Verständlichkeit und Inklusivität der Stellenanzeige.',
                'applications_summarize' => 'Fasse die bereitgestellten Bewerbungsinformationen neutral zusammen.',
                'candidate_compare' => 'Vergleiche berufliche Anforderungen anhand erklärbarer, nicht geschützter Kriterien.',
                'interview_questions' => 'Erzeuge arbeitsbezogene, faire Interviewfragen.',
                'cv_improve' => 'Verbessere Struktur und Formulierungen des Lebenslaufs.',
                'cover_letter' => 'Erzeuge einen individuellen Anschreibenentwurf.',
                'profile_improve' => 'Gib konkrete Vorschläge zur Profilverbesserung.',
                'translate' => 'Übersetze vollständig und erhalte Bedeutung und Format.',
                'interview_training' => 'Erstelle ein realistisches Interviewtraining mit Feedbackhinweisen.',
                default => 'Bearbeite die Eingabe.',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $task): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'suggestions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'caveats' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['title', 'content', 'suggestions', 'caveats'],
        ];
    }

    private function modelFor(string $task): string
    {
        return in_array($task, ['translate', 'applications_summarize'], true)
            ? (string) config('services.openai.economy_model')
            : (string) config('services.openai.quality_model');
    }
}
