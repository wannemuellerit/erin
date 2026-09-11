<?php

namespace App\Services\Visa;

use App\Enums\VisaCaseStatus;
use App\Models\Company;
use App\Models\CompanyUsagePeriod;
use App\Models\EntitlementLedger;
use App\Models\VisaCase;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisaCaseWorkflow
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{action: string, version: int, assigned_to?: int|null, reason?: string|null}  $input
     */
    public function apply(VisaCase $case, array $input, Request $request): VisaCase
    {
        return DB::transaction(function () use ($case, $input, $request): VisaCase {
            /** @var VisaCase $locked */
            $locked = VisaCase::query()->lockForUpdate()->findOrFail($case->getKey());
            if ($this->isIdempotentRetry($locked, $input)) {
                return $locked;
            }
            if ($locked->version !== $input['version']) {
                throw ValidationException::withMessages([
                    'version' => __('Der Visa-Fall wurde zwischenzeitlich geändert. Bitte neu laden.'),
                ]);
            }

            $before = $locked->only([
                'status', 'assigned_to', 'blocked_reason', 'closed_reason',
                'completed_at', 'credit_status', 'credit_source', 'version',
            ]);
            $action = $input['action'];
            $reason = $input['reason'] ?? null;

            match ($action) {
                'assign' => $locked->forceFill([
                    'assigned_to' => $input['assigned_to'] ?? null,
                ]),
                'block' => $this->block($locked, $reason),
                'resume' => $this->resume($locked),
                'not_required' => $this->closeAsNotRequired($locked, $reason),
                'complete' => $this->complete($locked, $reason),
                default => throw ValidationException::withMessages([
                    'action' => __('Diese Visa-Aktion ist nicht erlaubt.'),
                ]),
            };

            $locked->version++;
            $locked->save();
            $locked->events()->create([
                'actor_id' => $request->user()?->getKey(),
                'event' => 'visa.case.'.$action,
                'visibility' => $action === 'assign' ? 'platform' : 'shared',
                'payload' => [
                    'from_status' => $before['status']->value,
                    'to_status' => $locked->status->value,
                    'reason' => $reason,
                ],
            ]);
            $this->audit->record(
                'visa.case_'.$action,
                $locked,
                before: $this->serializable($before),
                after: $this->serializable($locked->only(array_keys($before))),
                metadata: ['reason' => $reason],
                request: $request,
                companyId: (int) $locked->company_id,
            );

            return $locked->refresh();
        }, 3);
    }

    private function block(VisaCase $case, ?string $reason): void
    {
        if (! in_array($case->status, [VisaCaseStatus::Draft, VisaCaseStatus::Active], true)) {
            $this->invalidTransition();
        }
        $case->forceFill([
            'status' => VisaCaseStatus::Blocked,
            'blocked_reason' => $reason,
            'completed_at' => null,
        ]);
    }

    private function resume(VisaCase $case): void
    {
        if (! in_array($case->status, [VisaCaseStatus::Blocked, VisaCaseStatus::Cancelled], true)) {
            $this->invalidTransition();
        }
        if ($case->credit_status === 'refunded') {
            /** @var Company $company */
            $company = Company::query()->with('plan')->findOrFail($case->company_id);
            $credit = $this->entitlements->consumeVisaCredit($company, $case->getKey());
            $case->forceFill([
                'credit_status' => 'consumed',
                'credit_source' => $credit['source'],
                'credit_usage_period_id' => $credit['usage_period_id'],
                'credit_ledger_id' => $credit['ledger_id'],
            ]);
        }
        $case->forceFill([
            'status' => VisaCaseStatus::Active,
            'blocked_reason' => null,
            'closed_reason' => null,
            'completed_at' => null,
        ]);
    }

    private function closeAsNotRequired(VisaCase $case, ?string $reason): void
    {
        if ($case->status === VisaCaseStatus::Completed) {
            $this->invalidTransition();
        }
        $this->refundCredit($case);
        $case->forceFill([
            'status' => VisaCaseStatus::Cancelled,
            'blocked_reason' => null,
            'closed_reason' => $reason,
            'completed_at' => now(),
        ]);
        $case->steps()->whereNotIn('status', ['completed', 'not_required'])->update([
            'status' => 'not_required',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function complete(VisaCase $case, ?string $reason): void
    {
        if (! in_array($case->status, [VisaCaseStatus::Active, VisaCaseStatus::Blocked], true)) {
            $this->invalidTransition();
        }
        $case->forceFill([
            'status' => VisaCaseStatus::Completed,
            'progress' => 100,
            'blocked_reason' => null,
            'closed_reason' => $reason,
            'completed_at' => now(),
        ]);
    }

    private function refundCredit(VisaCase $case): void
    {
        if ($case->credit_status === 'refunded') {
            return;
        }
        if ($case->credit_source === 'included' && $case->credit_usage_period_id !== null) {
            $period = CompanyUsagePeriod::query()
                ->lockForUpdate()
                ->find($case->credit_usage_period_id);
            if ($period instanceof CompanyUsagePeriod && $period->visa_credits_used > 0) {
                $period->decrement('visa_credits_used');
            }
        } elseif ($case->credit_source === 'purchased') {
            $exists = EntitlementLedger::query()
                ->where('company_id', $case->company_id)
                ->where('source', 'visa_case_refund')
                ->where('reference_type', 'visa_case')
                ->where('reference_id', $case->getKey())
                ->exists();
            if (! $exists) {
                EntitlementLedger::query()->create([
                    'company_id' => $case->company_id,
                    'resource' => 'visa',
                    'amount' => 1,
                    'source' => 'visa_case_refund',
                    'reference_type' => 'visa_case',
                    'reference_id' => $case->getKey(),
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'credit' => __('Die Herkunft dieses historischen Visa-Credits muss vor der Rückgabe manuell geklärt werden.'),
            ]);
        }
        $case->forceFill(['credit_status' => 'refunded']);
    }

    /** @param array<string, mixed> $input */
    private function isIdempotentRetry(VisaCase $case, array $input): bool
    {
        return match ($input['action']) {
            'assign' => $case->assigned_to === ($input['assigned_to'] ?? null),
            'block' => $case->status === VisaCaseStatus::Blocked
                && $case->blocked_reason === ($input['reason'] ?? null),
            'resume' => $case->status === VisaCaseStatus::Active,
            'not_required' => $case->status === VisaCaseStatus::Cancelled
                && $case->closed_reason === ($input['reason'] ?? null),
            'complete' => $case->status === VisaCaseStatus::Completed
                && $case->closed_reason === ($input['reason'] ?? null),
            default => false,
        };
    }

    private function invalidTransition(): never
    {
        throw ValidationException::withMessages([
            'action' => __('Dieser Visa-Statusübergang ist nicht erlaubt.'),
        ]);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function serializable(array $values): array
    {
        return collect($values)->map(fn (mixed $value): mixed => $value instanceof VisaCaseStatus
            ? $value->value
            : $value)->all();
    }
}
