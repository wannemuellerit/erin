<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReferralStatus;
use App\Http\Requests\Admin\UpdateReferralRequest;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string'],
        ]);

        $referrals = Referral::query()
            ->with([
                'referralCode:id,user_id,code,commission_cents,currency',
                'referralCode.user:id,name,email',
                'referredUser:id,name,email',
                'application:id,job_posting_id,status',
                'application.jobPosting:id,company_id,title',
                'application.jobPosting.company:id,name',
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('referralCode', fn (Builder $query): Builder => $query->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('referralCode.user', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('referredUser', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(
                isset($filters['status']) && ReferralStatus::tryFrom($filters['status']) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Referrals', [
            'referrals' => $referrals,
            'filters' => $filters,
            'statuses' => array_map(
                static fn (ReferralStatus $status): string => $status->value,
                ReferralStatus::cases(),
            ),
            'summary' => [
                'holding_cents' => (int) Referral::query()
                    ->where('status', ReferralStatus::Holding)
                    ->sum('commission_cents'),
                'approved_cents' => (int) Referral::query()
                    ->where('status', ReferralStatus::Approved)
                    ->sum('commission_cents'),
                'paid_cents' => (int) Referral::query()
                    ->where('status', ReferralStatus::Paid)
                    ->sum('commission_cents'),
            ],
        ]);
    }

    public function update(UpdateReferralRequest $request, Referral $referral): RedirectResponse
    {
        $validated = $request->validated();
        $nextStatus = ReferralStatus::from($validated['status']);
        $rawStatus = $referral->getAttribute('status');
        $currentStatus = $rawStatus instanceof ReferralStatus
            ? $rawStatus
            : ReferralStatus::from((string) $rawStatus);

        if (
            $nextStatus === ReferralStatus::Approved
            && (
                ! in_array($currentStatus, [ReferralStatus::Hired, ReferralStatus::Holding], true)
                || $referral->getAttribute('hold_until') === null
                || $this->dateIsFuture($referral->getAttribute('hold_until'))
            )
        ) {
            throw ValidationException::withMessages([
                'status' => __('Die Provision kann erst nach der 30-Tage-Haltefrist freigegeben werden.'),
            ]);
        }

        if ($nextStatus === ReferralStatus::Paid && $currentStatus !== ReferralStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => __('Nur bereits freigegebene Provisionen können als ausgezahlt markiert werden.'),
            ]);
        }

        if ($currentStatus === ReferralStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => __('Eine bereits ausgezahlte Provision kann nicht mehr geändert werden.'),
            ]);
        }

        $before = [
            'status' => $this->enumValue($referral->getAttribute('status')),
            'approved_at' => $this->auditDate($referral->getAttribute('approved_at')),
            'paid_at' => $this->auditDate($referral->getAttribute('paid_at')),
            'metadata' => $referral->metadata,
        ];

        $rawMetadata = $referral->getAttribute('metadata');
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];
        if (filled($validated['reason'] ?? null)) {
            $metadata['admin_reason'] = $validated['reason'];
        }
        if ($nextStatus === ReferralStatus::Paid) {
            $metadata['payout_reference'] = $validated['payout_reference'];
        }

        $referral->update([
            'status' => $nextStatus,
            'approved_at' => $nextStatus === ReferralStatus::Approved
                ? now()
                : $referral->approved_at,
            'paid_at' => $nextStatus === ReferralStatus::Paid ? now() : $referral->paid_at,
            'metadata' => $metadata,
        ]);

        $this->audit(
            $request,
            'admin.referral.updated',
            $referral,
            $before,
            [
                'status' => $this->enumValue($referral->getAttribute('status')),
                'approved_at' => $this->auditDate($referral->getAttribute('approved_at')),
                'paid_at' => $this->auditDate($referral->getAttribute('paid_at')),
                'metadata' => $referral->metadata,
            ],
        );

        return back()->with('success', __('Der Referral-Status wurde aktualisiert.'));
    }
}
