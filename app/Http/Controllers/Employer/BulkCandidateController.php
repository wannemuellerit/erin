<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCandidateBulkBatch;
use App\Models\CandidateBulkBatch;
use App\Models\CandidateProfile;
use App\Services\Companies\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BulkCandidateController extends Controller
{
    public function invite(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        return $this->createBatch($request, $currentCompany, 'invite');
    }

    public function message(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        return $this->createBatch($request, $currentCompany, 'message');
    }

    public function cancel(Request $request, CandidateBulkBatch $batch, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        abort_unless($batch->company_id === $company->getKey(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        abort_unless(in_array($batch->status, ['queued', 'processing'], true), 422);
        $batch->update(['cancellation_requested_at' => $batch->cancellation_requested_at ?? now()]);

        return back()->with('success', __('Der Abbruch der Bulk-Aktion wurde angefordert.'));
    }

    public function report(Request $request, CandidateBulkBatch $batch, CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->forRequest($request);
        abort_unless($batch->company_id === $company->getKey(), 404);

        return response()->json([
            'id' => $batch->getKey(),
            'action' => $batch->action,
            'status' => $batch->status,
            'total' => $batch->total,
            'processed' => $batch->processed,
            'succeeded' => $batch->succeeded,
            'failed' => $batch->failed,
            'items' => $batch->items()->orderBy('id')->get([
                'candidate_profile_id', 'status', 'reason', 'result_type', 'result_id',
            ]),
        ]);
    }

    private function createBatch(Request $request, CurrentCompany $currentCompany, string $action): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'selection_mode' => ['nullable', Rule::in(['ids', 'all_results'])],
            'candidate_ids' => ['nullable', 'required_unless:selection_mode,all_results', 'array', 'min:1', 'max:100'],
            'candidate_ids.*' => ['required', 'integer', 'distinct'],
            'filter_snapshot' => ['nullable', 'required_if:selection_mode,all_results', 'array'],
            'filter_snapshot.search' => ['nullable', 'string', 'max:200'],
            'filter_snapshot.country' => ['nullable', 'string', 'size:2'],
            'filter_snapshot.occupation' => ['nullable', 'integer'],
            'filter_snapshot.experience' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'filter_snapshot.visa' => ['nullable', Rule::in(['required', 'not_required'])],
            'job_posting_id' => [$action === 'invite' ? 'required' : 'nullable', 'integer'],
            'message' => [$action === 'message' ? 'required' : 'nullable', 'string', 'max:10000'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        if ($action === 'invite') {
            $company->jobPostings()->published()->findOrFail($validated['job_posting_id']);
        }

        $selectionMode = (string) ($validated['selection_mode'] ?? 'ids');
        $snapshot = $selectionMode === 'all_results'
            ? $this->canonicalSnapshot($validated['filter_snapshot'] ?? [])
            : null;
        $candidateIds = $selectionMode === 'all_results'
            ? $this->resolveSnapshot($snapshot ?? [])
            : array_map('intval', $validated['candidate_ids'] ?? []);
        $payload = array_filter([
            'job_posting_id' => $validated['job_posting_id'] ?? null,
            'message' => $validated['message'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
        $requestHash = hash('sha256', json_encode([
            'action' => $action,
            'selection_mode' => $selectionMode,
            'snapshot' => $snapshot,
            'candidate_ids' => $candidateIds,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));

        /** @var CandidateBulkBatch $batch */
        $batch = DB::transaction(function () use (
            $company, $user, $validated, $action, $selectionMode, $snapshot,
            $candidateIds, $payload, $requestHash,
        ): CandidateBulkBatch {
            $existing = CandidateBulkBatch::query()
                ->where('company_id', $company->getKey())
                ->where('created_by', $user->getKey())
                ->where('idempotency_key', $validated['idempotency_key'])
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('Dieser Wiederholungsschlüssel wurde bereits für eine andere Aktion verwendet.'),
                    ]);
                }

                return $existing;
            }

            /** @var CandidateBulkBatch $batch */
            $batch = CandidateBulkBatch::query()->create([
                'company_id' => $company->getKey(),
                'created_by' => $user->getKey(),
                'idempotency_key' => $validated['idempotency_key'],
                'action' => $action,
                'selection_mode' => $selectionMode,
                'filter_snapshot' => $snapshot,
                'payload' => $payload,
                'request_hash' => $requestHash,
                'total' => count($candidateIds),
            ]);
            CandidateProfile::query()->whereKey($candidateIds)->get(['id', 'updated_at'])
                ->each(fn (CandidateProfile $candidate) => $batch->items()->create([
                    'candidate_profile_id' => $candidate->getKey(),
                    'candidate_updated_at' => $candidate->updated_at,
                ]));

            return $batch;
        }, 3);

        if ($batch->status === 'queued') {
            ProcessCandidateBulkBatch::dispatch($batch->getKey());
        }

        return back()->with('success', __('Die Bulk-Aktion :id wurde mit :count Ziel(en) eingeplant.', [
            'id' => $batch->getKey(),
            'count' => $batch->total,
        ]));
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function canonicalSnapshot(array $filters): array
    {
        ksort($filters);

        return array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $filters
     * @return list<int>
     */
    private function resolveSnapshot(array $filters): array
    {
        $ids = CandidateProfile::query()->published()
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('current_position', 'like', $term)
                    ->orWhere('desired_position', 'like', $term));
            })
            ->when(filled($filters['country'] ?? null), fn (Builder $query) => $query
                ->where('current_country_code', mb_strtoupper((string) $filters['country'])))
            ->when(isset($filters['occupation']), fn (Builder $query) => $query
                ->where('occupation_id', (int) $filters['occupation']))
            ->when(isset($filters['experience']), fn (Builder $query) => $query
                ->where('experience_years', '>=', (float) $filters['experience']))
            ->when(($filters['visa'] ?? null) === 'required', fn (Builder $query) => $query->where('requires_visa', true))
            ->when(($filters['visa'] ?? null) === 'not_required', fn (Builder $query) => $query->where('requires_visa', false))
            ->orderBy('id')->limit(500)->pluck('id')->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
