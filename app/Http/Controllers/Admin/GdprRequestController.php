<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GdprRequestStatus;
use App\Http\Requests\Admin\StoreGdprRequestRequest;
use App\Http\Requests\Admin\UpdateGdprRequestRequest;
use App\Models\GdprRequest;
use Illuminate\Http\RedirectResponse;

class GdprRequestController extends AdminController
{
    public function store(StoreGdprRequestRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $gdprRequest = GdprRequest::query()->create([
            'user_id' => $validated['user_id'],
            'type' => $validated['type'],
            'status' => GdprRequestStatus::Requested,
            'reason' => $validated['reason'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
        ]);

        $this->audit(
            $request,
            'admin.gdpr_request.created',
            $gdprRequest,
            after: $this->snapshot($gdprRequest),
        );

        return back()->with('success', __('Die DSGVO-Anfrage wurde angelegt.'));
    }

    public function update(
        UpdateGdprRequestRequest $request,
        GdprRequest $gdprRequest,
    ): RedirectResponse {
        $validated = $request->validated();
        $status = GdprRequestStatus::from($validated['status']);
        $before = $this->snapshot($gdprRequest);

        $verified = in_array(
            $status,
            [
                GdprRequestStatus::Verified,
                GdprRequestStatus::Processing,
                GdprRequestStatus::Completed,
            ],
            true,
        );

        $gdprRequest->update([
            'handled_by' => $request->user()?->getKey(),
            'type' => $validated['type'],
            'status' => $status,
            'reason' => $validated['reason'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
            'verified_at' => $verified ? ($gdprRequest->verified_at ?? now()) : null,
            'completed_at' => $status === GdprRequestStatus::Completed
                ? ($gdprRequest->completed_at ?? now())
                : null,
        ]);

        $this->audit(
            $request,
            'admin.gdpr_request.updated',
            $gdprRequest,
            $before,
            $this->snapshot($gdprRequest),
        );

        return back()->with('success', __('Die DSGVO-Anfrage wurde aktualisiert.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(GdprRequest $gdprRequest): array
    {
        return [
            'user_id' => $gdprRequest->user_id,
            'handled_by' => $gdprRequest->handled_by,
            'type' => $gdprRequest->type,
            'status' => $this->enumValue($gdprRequest->getAttribute('status')),
            'reason' => $gdprRequest->reason,
            'due_at' => $this->auditDate($gdprRequest->due_at),
            'verified_at' => $this->auditDate($gdprRequest->verified_at),
            'completed_at' => $this->auditDate($gdprRequest->completed_at),
        ];
    }
}
