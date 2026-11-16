<?php

namespace App\Http\Controllers;

use App\Enums\CandidateDocumentStatus;
use App\Enums\PartnerServiceType;
use App\Enums\UserRole;
use App\Models\CandidateDocument;
use App\Models\JobApplication;
use App\Models\PartnerCase;
use App\Models\PartnerCaseArtifact;
use App\Models\PartnerDocumentGrant;
use App\Models\PartnerMember;
use App\Models\PartnerOffering;
use App\Services\Companies\CurrentCompany;
use App\Services\Partners\PartnerCaseAccess;
use App\Services\Partners\PartnerCaseWorkflow;
use App\Services\Partners\PartnerServiceGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartnerServiceController extends Controller
{
    public function index(Request $request, PartnerServiceGate $gate, CurrentCompany $currentCompany): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $query = PartnerCase::query()->with(['offering:id,title,version', 'organization:id,name,category', 'events' => fn ($query) => $query->latest()->limit(25), 'tasks' => fn ($query) => $query->where('status', 'open')->latest()]);
        if ($user->role === UserRole::Candidate) {
            $query->where('candidate_user_id', $user->getKey());
        } elseif ($user->role === UserRole::Company) {
            $company = $currentCompany->forUser($user);
            abort_if($company === null, 403);
            $query->where('company_id', $company->getKey());
        } elseif ($user->role === UserRole::Partner) {
            $member = PartnerMember::query()->with('organization')->where('user_id', $user->getKey())
                ->whereNotNull('accepted_at')->whereNull('revoked_at')->firstOrFail();
            abort_unless($member->organization->isOperational(), 403);
            $query->where('partner_organization_id', $member->partner_organization_id)
                ->where(fn (Builder $query) => $query->where('assigned_member_id', $member->getKey())->orWhereRaw('? = ?', [$member->role, 'admin']))
                ->whereNotNull('consented_at')->whereNull('withdrawn_at')->where('consent_expires_at', '>', now());
        } else {
            abort(403);
        }

        /** @var Collection<int, PartnerCase> $caseModels */
        $caseModels = $query->latest()->get();
        $cases = $caseModels->map(function (PartnerCase $case) use ($user): array {
            $events = $case->events;
            if ($user->role === UserRole::Company) {
                $events = $events->where('visible_to_company', true);
            }

            return [
                'id' => $case->public_id, 'service_type' => $case->service_type->value,
                'target_country_code' => $case->target_country_code, 'status' => $case->status,
                'public_status' => $case->public_status, 'purpose' => $case->purpose,
                'consent_expires_at' => $case->consent_expires_at?->toIso8601String(),
                'withdrawn_at' => $case->withdrawn_at?->toIso8601String(),
                'service_details' => $case->service_details,
                'offering' => $case->offering, 'organization' => $case->organization,
                'events' => $events->values(), 'tasks' => $case->tasks,
            ];
        });

        $offerings = collect();
        if ($user->role === UserRole::Candidate) {
            /** @var Collection<int, PartnerOffering> $offeringModels */
            $offeringModels = PartnerOffering::query()->with('organization')->where('is_active', true)->get();
            $offerings = $offeringModels
                ->filter(fn (PartnerOffering $offering): bool => $gate->offeringIsAvailable($offering))
                ->map(fn (PartnerOffering $offering): array => [
                    'id' => $offering->getKey(), 'service_type' => $offering->service_type->value,
                    'country_code' => $offering->country_code, 'title' => $offering->title,
                    'description' => $offering->description, 'attributes' => $offering->attributes,
                    'currency_code' => $offering->currency_code, 'price_minor' => $offering->price_minor,
                    'version' => $offering->version, 'partner' => $offering->organization->name,
                ])->values();
        }

        return Inertia::render('services/Index', [
            'mode' => $user->role->value, 'cases' => $cases, 'offerings' => $offerings,
            'serviceTypes' => PartnerServiceType::values(),
            'privacyNotice' => __('Daten werden nur nach Ihrer ausdrücklichen Einwilligung, zweckgebunden und zeitlich begrenzt geteilt. Arbeitgeber sehen ausschließlich den Prozessstatus.'),
        ]);
    }

    public function store(Request $request, PartnerServiceGate $gate, CurrentCompany $currentCompany, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless(in_array($user->role, [UserRole::Candidate, UserRole::Company], true), 403);

        $validated = $request->validate([
            'service_type' => ['required', Rule::enum(PartnerServiceType::class)],
            'target_country_code' => ['required', 'string', 'size:2'],
            'offering_id' => ['nullable', 'integer', 'exists:partner_offerings,id'],
            'candidate_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'purpose' => ['required', 'string', 'max:300'],
            'requested_services' => ['nullable', 'array', 'max:20'],
            'requested_services.*' => ['string', 'max:80'],
            'shared_data_categories' => ['nullable', 'array', 'max:20'],
            'shared_data_categories.*' => ['string', Rule::in(['identity', 'contact', 'application', 'documents', 'employment', 'billing'])],
            'service_details' => ['nullable', 'array'],
            'service_details.source_language' => [Rule::requiredIf($request->string('service_type')->toString() === PartnerServiceType::Translation->value), 'string', 'size:2'],
            'service_details.target_language' => [Rule::requiredIf($request->string('service_type')->toString() === PartnerServiceType::Translation->value), 'string', 'size:2'],
            'service_details.document_type' => [Rule::requiredIf($request->string('service_type')->toString() === PartnerServiceType::Translation->value), 'string', 'max:80'],
            'service_details.page_count' => [Rule::requiredIf($request->string('service_type')->toString() === PartnerServiceType::Translation->value), 'integer', 'min:1', 'max:1000'],
            'service_details.deadline' => [Rule::requiredIf($request->string('service_type')->toString() === PartnerServiceType::Translation->value), 'date', 'after:today'],
            'service_details.offer_minor' => ['nullable', 'integer', 'min:0'],
            'consent' => ['required', 'accepted'],
        ]);
        $type = PartnerServiceType::from($validated['service_type']);
        $country = strtoupper($validated['target_country_code']);
        abort_unless($gate->isEnabled($type, $country), 404, __('Dieser Dienst ist für das Zielland noch nicht freigegeben.'));

        $offering = isset($validated['offering_id']) ? PartnerOffering::query()->with('organization')->find($validated['offering_id']) : null;
        abort_if(isset($validated['offering_id']) && ! $offering instanceof PartnerOffering, 404);
        if ($offering !== null) {
            abort_unless($offering->service_type === $type && $offering->country_code === $country && $gate->offeringIsAvailable($offering), 422);
        }

        $company = $user->role === UserRole::Company ? $currentCompany->forUser($user) : null;
        $candidateId = $user->role === UserRole::Candidate ? $user->getKey() : (int) ($validated['candidate_user_id'] ?? 0);
        if ($user->role === UserRole::Company) {
            abort_if($company === null || $candidateId === 0, 422);
            abort_unless(JobApplication::query()->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
                ->whereHas('candidateProfile', fn ($query) => $query->where('user_id', $candidateId))->exists(), 403);
            abort_unless($type === PartnerServiceType::Payroll, 422, __('Arbeitgeber dürfen nur Payroll-Fälle anlegen.'));
        }

        $case = PartnerCase::query()->create([
            'service_type' => $type, 'candidate_user_id' => $candidateId, 'company_id' => $company?->getKey(),
            'partner_organization_id' => $offering?->partner_organization_id, 'partner_offering_id' => $offering?->getKey(),
            'target_country_code' => $country, 'status' => $offering ? 'ready_to_transfer' : 'manual_review',
            'public_status' => $offering ? 'ready' : 'support_review', 'purpose' => $validated['purpose'],
            'requested_services' => $validated['requested_services'] ?? [],
            'service_details' => $validated['service_details'] ?? [],
            'shared_data_categories' => $validated['shared_data_categories'] ?? [],
            'consented_at' => now(), 'consent_expires_at' => now()->addDays(90),
            'correlation_id' => $request->attributes->get('correlation_id'), 'retention_until' => now()->addYear(),
        ]);
        $workflow->record($case, 'created', __('Servicefall angelegt.'), $user, true);

        return back()->with('success', __('Servicefall wurde angelegt.'));
    }

    public function transfer(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || ! $access->canManage($user, $case), 403);
        $workflow->transfer($case->load('organization'), $user);

        return back()->with('success', __('Der Fall wurde idempotent übermittelt.'));
    }

    public function update(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || ! $access->canManage($user, $case), 403);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'waiting_for_candidate', 'submitted_to_authority', 'booked', 'completed', 'rejected'])],
            'public_status' => ['required', Rule::in(['in_progress', 'action_required', 'submitted', 'booked', 'completed', 'rejected'])],
            'summary' => ['required', 'string', 'max:300'],
        ]);
        $case->update(Arr::only($validated, ['status', 'public_status']));
        $workflow->record($case, 'status_changed', $validated['summary'], $user, true);
        if (in_array($validated['status'], ['waiting_for_candidate', 'rejected'], true)) {
            $workflow->requestCandidateAction($case, $validated['status'], $validated['summary'], $user);
        }

        return back()->with('success', __('Status aktualisiert.'));
    }

    public function withdraw(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || $case->candidate_user_id !== $user->getKey() || ! $access->canView($user, $case), 403);
        $workflow->withdraw($case, $user);

        return back()->with('success', __('Einwilligung wurde widerrufen.'));
    }

    public function switchOffering(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerServiceGate $gate, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || $case->candidate_user_id !== $user->getKey() || ! $access->canView($user, $case), 403);
        $validated = $request->validate(['offering_id' => ['required', 'integer', 'exists:partner_offerings,id'], 'consent' => ['required', 'accepted']]);
        $offering = PartnerOffering::query()->with('organization')->find($validated['offering_id']);
        abort_unless($offering instanceof PartnerOffering, 404);
        abort_unless($offering->service_type === $case->service_type && $offering->country_code === $case->target_country_code && $gate->offeringIsAvailable($offering), 422);

        DB::transaction(function () use ($case, $offering, $user, $workflow): void {
            $case->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $case->update(['partner_organization_id' => $offering->partner_organization_id, 'partner_offering_id' => $offering->getKey(), 'assigned_member_id' => null,
                'status' => 'ready_to_transfer', 'public_status' => 'ready', 'consented_at' => now(), 'consent_expires_at' => now()->addDays(90), 'withdrawn_at' => null, 'external_reference' => null]);
            $workflow->record($case, 'partner_switched', __('Partner gewechselt; alte Freigaben wurden beendet.'), $user, true);
        });

        return back()->with('success', __('Partner wurde gewechselt.'));
    }

    public function storeArtifact(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || ! $access->canManage($user, $case), 403);
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in($case->service_type->allowedArtifactKinds())],
            'title' => ['required', 'string', 'max:160'], 'payload' => ['nullable', 'array', 'max:100'],
            'status' => ['required', Rule::in(['draft', 'validated', 'submitted', 'accepted', 'rejected'])],
            'authority' => ['nullable', 'string', 'max:160'], 'valid_until' => ['nullable', 'date', 'after:today'],
            'source_document_id' => ['nullable', 'integer', 'exists:candidate_documents,id'],
        ]);
        $this->rejectSecrets($validated['payload'] ?? []);
        if ($case->service_type === PartnerServiceType::Recognition && $validated['status'] === 'accepted') {
            abort_unless(filled($validated['authority'] ?? null), 422, __('Eine menschliche Entscheidungsstelle muss angegeben werden.'));
            $member = $user->role === UserRole::Partner ? $access->assignedPartnerMember($user, $case) : null;
            abort_unless($user->role === UserRole::SuperAdmin || in_array($member?->role, ['authority', 'admin'], true), 403);
        }

        $source = null;
        if ($validated['kind'] === 'translation') {
            abort_unless(isset($validated['source_document_id']), 422, __('Eine freigegebene Quelldokumentversion ist erforderlich.'));
            $source = CandidateDocument::query()
                ->whereHas('candidateProfile', fn ($query) => $query->where('user_id', $case->candidate_user_id))
                ->find($validated['source_document_id']);
            abort_unless($source instanceof CandidateDocument
                && $source->status === CandidateDocumentStatus::Verified
                && $source->scan_completed_at !== null
                && $source->scan_result === 'clean'
                && filled($source->sha256)
                && $case->grants()->where('candidate_document_id', $source->getKey())->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 422,
                __('Nur eine aktiv freigegebene, virengeprüfte Dokumentversion darf verwendet werden.'));
            abort_unless(filled(data_get($validated, 'payload.translator')), 422, __('Übersetzer- oder Beglaubigungsmetadaten fehlen.'));
        }

        DB::transaction(function () use ($case, $user, $validated, $source): void {
            PartnerCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $version = (int) PartnerCaseArtifact::query()->where('partner_case_id', $case->getKey())->where('kind', $validated['kind'])->max('version') + 1;
            PartnerCaseArtifact::query()->create([
                'partner_case_id' => $case->getKey(), 'created_by' => $user->getKey(), 'source_document_id' => $source?->getKey(),
                'kind' => $validated['kind'], 'title' => $validated['title'],
                'encrypted_payload' => $validated['payload'] ?? [], 'checksum' => hash('sha256', json_encode($validated['payload'] ?? [], JSON_THROW_ON_ERROR)),
                'source_checksum' => $source?->sha256, 'status' => $validated['status'], 'authority' => $validated['authority'] ?? null,
                'version' => $version, 'valid_until' => $validated['valid_until'] ?? null,
            ]);
        }, 3);
        $workflow->record($case, 'artifact_added', __('Ein versionierter Nachweis wurde hinzugefügt.'), $user, false);

        return back()->with('success', __('Nachweis gespeichert.'));
    }

    public function grantDocument(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || $case->candidate_user_id !== $user->getKey() || ! $access->canView($user, $case) || ! $case->hasActiveConsent(), 403);
        abort_if($case->partner_organization_id === null, 422);
        $validated = $request->validate(['document_id' => ['required', 'integer'], 'purpose' => ['required', 'string', 'max:300'], 'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.$case->consent_expires_at?->toDateTimeString()]]);
        $document = CandidateDocument::query()->whereHas('candidateProfile', fn ($query) => $query->where('user_id', $user->getKey()))->find($validated['document_id']);
        abort_unless($document instanceof CandidateDocument, 404);
        abort_unless($document->status === CandidateDocumentStatus::Verified
            && $document->scan_completed_at !== null
            && $document->scan_result === 'clean'
            && filled($document->sha256), 422,
            __('Nur virengeprüfte, unverändert referenzierbare Dokumentversionen dürfen freigegeben werden.'));
        PartnerDocumentGrant::query()->updateOrCreate(
            ['partner_case_id' => $case->getKey(), 'candidate_document_id' => $document->getKey(), 'partner_organization_id' => $case->partner_organization_id],
            ['purpose' => $validated['purpose'], 'document_sha256' => $document->sha256, 'expires_at' => $validated['expires_at'], 'revoked_at' => null],
        );
        $workflow->record($case, 'document_granted', __('Zeitlich begrenzte Dokumentfreigabe erteilt.'), $user, false);

        return back()->with('success', __('Dokument wurde zeitlich begrenzt freigegeben.'));
    }

    public function export(Request $request, PartnerCase $case, PartnerCaseAccess $access): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $case->candidate_user_id !== $user->getKey() || ! $access->canView($user, $case), 403);

        return response()->json(['case' => $case->only(['public_id', 'service_type', 'target_country_code', 'status', 'public_status', 'purpose', 'created_at']),
            'events' => $case->events()->where('visible_to_candidate', true)->get(['event_type', 'summary', 'created_at']),
            'artifacts' => $case->artifacts()->get(['kind', 'title', 'status', 'authority', 'version', 'valid_until'])]);
    }

    public function destroy(Request $request, PartnerCase $case, PartnerCaseAccess $access, PartnerCaseWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || $case->candidate_user_id !== $user->getKey() || ! $access->canView($user, $case), 403);
        if ($case->withdrawn_at === null) {
            $workflow->withdraw($case, $user);
        }
        $case->delete();

        return back()->with('success', __('Servicefalldaten wurden gelöscht.'));
    }

    /** @param array<string, mixed> $payload */
    private function rejectSecrets(array $payload): void
    {
        $keys = array_map('strtolower', Arr::dot($payload) === [] ? [] : array_keys(Arr::dot($payload)));
        foreach ($keys as $key) {
            abort_if(preg_match('/(^|\.)(password|passwort|pin|tan|credential|activation_code|health_data|diagnosis)(\.|$)/', $key) === 1, 422, __('Zugangsdaten, Aktivierungscodes und Gesundheitsdaten dürfen nicht gespeichert werden.'));
        }
    }
}
