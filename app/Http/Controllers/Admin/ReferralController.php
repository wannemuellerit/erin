<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReferralStatus;
use App\Http\Requests\Admin\UpdateReferralRequest;
use App\Models\Referral;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'statusHistory.actor:id,name',
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
        $referral = DB::transaction(function () use (
            $referral,
            $nextStatus,
            $validated,
            $request,
        ): Referral {
            /** @var Referral $locked */
            $locked = Referral::query()->lockForUpdate()->findOrFail($referral->getKey());
            $rawStatus = $locked->getAttribute('status');
            $currentStatus = $rawStatus instanceof ReferralStatus
                ? $rawStatus
                : ReferralStatus::from((string) $rawStatus);

            if (
                $nextStatus === ReferralStatus::Approved
                && (
                    ! in_array($currentStatus, [ReferralStatus::Hired, ReferralStatus::Holding], true)
                    || $locked->getAttribute('hold_until') === null
                    || $this->dateIsFuture($locked->getAttribute('hold_until'))
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
                'status' => $this->enumValue($locked->getAttribute('status')),
                'approved_at' => $this->auditDate($locked->getAttribute('approved_at')),
                'paid_at' => $this->auditDate($locked->getAttribute('paid_at')),
                'metadata' => $locked->metadata,
            ];
            $rawMetadata = $locked->getAttribute('metadata');
            $metadata = is_array($rawMetadata) ? $rawMetadata : [];
            if (filled($validated['reason'] ?? null)) {
                $metadata['admin_reason'] = $validated['reason'];
            }
            if ($nextStatus === ReferralStatus::Paid) {
                $metadata['payout_reference'] = $validated['payout_reference'];
            }

            $locked->update([
                'status' => $nextStatus,
                'approved_at' => $nextStatus === ReferralStatus::Approved
                    ? now()
                    : $locked->approved_at,
                'paid_at' => $nextStatus === ReferralStatus::Paid ? now() : $locked->paid_at,
                'metadata' => $metadata,
            ]);

            $this->audit(
                $request,
                'admin.referral.updated',
                $locked,
                $before,
                [
                    'status' => $this->enumValue($locked->getAttribute('status')),
                    'approved_at' => $this->auditDate($locked->getAttribute('approved_at')),
                    'paid_at' => $this->auditDate($locked->getAttribute('paid_at')),
                    'metadata' => $locked->metadata,
                ],
            );

            return $locked;
        }, 3);

        $this->notifyReferrer($referral, $nextStatus);

        return back()->with('success', __('Der Referral-Status wurde aktualisiert.'));
    }

    private function notifyReferrer(Referral $referral, ReferralStatus $status): void
    {
        $copy = match ($status) {
            ReferralStatus::Approved => [
                'de' => ['title' => 'Referral-Provision freigegeben', 'message' => 'Deine Referral-Provision wurde zur Auszahlung freigegeben.'],
                'en' => ['title' => 'Referral commission approved', 'message' => 'Your referral commission has been approved for payout.'],
            ],
            ReferralStatus::Paid => [
                'de' => ['title' => 'Referral-Provision ausgezahlt', 'message' => 'Deine Referral-Provision wurde als ausgezahlt markiert.'],
                'en' => ['title' => 'Referral commission paid', 'message' => 'Your referral commission has been marked as paid.'],
            ],
            ReferralStatus::Rejected => [
                'de' => ['title' => 'Referral-Provision abgelehnt', 'message' => 'Deine Referral-Provision wurde nach Prüfung abgelehnt.'],
                'en' => ['title' => 'Referral commission rejected', 'message' => 'Your referral commission was rejected after review.'],
            ],
            default => null,
        };

        if ($copy === null) {
            return;
        }

        $referral->loadMissing('referralCode.user');
        $referral->referralCode->user->notify(new ActivityNotification([
            'event' => 'referral.'.$status->value,
            'translations' => $copy,
            'url' => route('referrals.index'),
            'referral_id' => $referral->getKey(),
        ]));
    }
}
