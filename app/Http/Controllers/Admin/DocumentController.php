<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Http\Requests\Admin\ReviewCandidateDocumentRequest;
use App\Models\CandidateDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'scan_result' => ['nullable', 'string', 'max:40'],
        ]);

        $documents = CandidateDocument::query()
            ->select([
                'id',
                'candidate_profile_id',
                'type',
                'title',
                'original_name',
                'mime_type',
                'size_bytes',
                'status',
                'rejection_reason',
                'expires_at',
                'verified_by',
                'verified_at',
                'scan_completed_at',
                'scan_result',
                'created_at',
            ])
            ->with([
                'candidateProfile:id,user_id,first_name,last_name,current_position',
                'candidateProfile.user:id,name,email',
                'verifier:id,name',
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('original_name', 'like', "%{$search}%")
                        ->orWhereHas('candidateProfile.user', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(
                isset($filters['status']) && CandidateDocumentStatus::tryFrom($filters['status']) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['type']) && CandidateDocumentType::tryFrom($filters['type']) !== null,
                fn (Builder $query): Builder => $query->where('type', $filters['type']),
            )
            ->when(
                $filters['scan_result'] ?? null,
                fn (Builder $query, string $scanResult): Builder => $query->where('scan_result', $scanResult),
            )
            ->orderByRaw(
                "case status when 'uploaded' then 0 when 'in_review' then 1 else 2 end",
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Documents', [
            'documents' => $documents,
            'filters' => $filters,
            'statuses' => array_map(
                static fn (CandidateDocumentStatus $status): string => $status->value,
                CandidateDocumentStatus::cases(),
            ),
            'types' => array_map(
                static fn (CandidateDocumentType $type): string => $type->value,
                CandidateDocumentType::cases(),
            ),
        ]);
    }

    public function review(
        ReviewCandidateDocumentRequest $request,
        CandidateDocument $document,
    ): RedirectResponse {
        $validated = $request->validated();
        $status = CandidateDocumentStatus::from($validated['status']);

        if (
            $status === CandidateDocumentStatus::Verified
            && ($document->scan_completed_at === null || $document->scan_result !== 'clean')
        ) {
            throw ValidationException::withMessages([
                'status' => __('Nur vollständig und sauber geprüfte Dateien können verifiziert werden.'),
            ]);
        }

        $before = [
            'status' => $document->status->value,
            'rejection_reason' => $document->rejection_reason,
            'verified_by' => $document->verified_by,
            'verified_at' => $this->auditDate($document->verified_at),
            'shared_with_employers' => $document->shared_with_employers,
        ];

        $document->update([
            'status' => $status,
            'rejection_reason' => $status === CandidateDocumentStatus::Rejected
                ? $validated['rejection_reason']
                : null,
            'verified_by' => $status === CandidateDocumentStatus::InReview
                ? null
                : $request->user()?->getKey(),
            'verified_at' => $status === CandidateDocumentStatus::Verified ? now() : null,
            'shared_with_employers' => $status === CandidateDocumentStatus::Verified
                ? $document->shared_with_employers
                : false,
        ]);

        $this->audit(
            $request,
            'admin.candidate_document.reviewed',
            $document,
            $before,
            [
                'status' => $document->status->value,
                'rejection_reason' => $document->rejection_reason,
                'verified_by' => $document->verified_by,
                'verified_at' => $this->auditDate($document->verified_at),
                'shared_with_employers' => $document->shared_with_employers,
            ],
        );

        return back()->with('success', __('Die Dokumentprüfung wurde gespeichert.'));
    }
}
