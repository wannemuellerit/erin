<?php

namespace App\Services\Billing;

use App\Enums\CompanyStatus;
use App\Enums\JobStatus;
use App\Models\Company;
use App\Models\CompanyUsagePeriod;
use App\Models\EntitlementLedger;
use App\Models\JobPosting;
use App\Models\Plan;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Company $company): array
    {
        $company->loadMissing('plan');
        $plan = $company->plan;

        if ($plan === null) {
            return [
                'plan' => null,
                'portal_access' => false,
                'jobs' => ['used' => 0, 'limit' => 0, 'remaining' => 0],
                'seats' => ['used' => 0, 'limit' => 0, 'remaining' => 0],
                'ai_credits' => ['used' => 0, 'limit' => 0, 'remaining' => 0],
                'boosts' => ['used' => 0, 'limit' => 0, 'remaining' => 0],
                'visa_credits' => ['used' => 0, 'limit' => 0, 'purchased' => 0, 'remaining' => 0],
            ];
        }

        $month = $this->monthlyUsage($company, false);
        $term = $this->termUsage($company, false);
        $monthAiUsed = $month instanceof CompanyUsagePeriod ? $month->ai_credits_used : 0;
        $termBoostsUsed = $term instanceof CompanyUsagePeriod ? $term->job_boosts_used : 0;
        $termVisaUsed = $term instanceof CompanyUsagePeriod ? $term->visa_credits_used : 0;
        $jobsUsed = JobPosting::query()
            ->where('company_id', $company->getKey())
            ->where('status', JobStatus::Published)
            ->count();
        $seatsUsed = $company->memberships()->whereNotNull('accepted_at')->count();
        $additionalSeats = $this->additionalSeatLimit($company);
        $seatLimit = $plan->seat_limit === null
            ? null
            : $plan->seat_limit + $additionalSeats;
        $purchasedVisa = $this->purchasedBalance($company, 'visa');

        return [
            'plan' => [
                'id' => $plan->getKey(),
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'term_months' => $plan->term_months,
            ],
            'portal_access' => $this->hasPortalAccess($company),
            'past_due' => $company->subscription_status === 'past_due',
            'renews_at' => $company->subscription_renews_at?->toIso8601String(),
            'cancel_at_period_end' => $company->cancel_at_period_end,
            'jobs' => $this->resource($jobsUsed, $plan->active_jobs_limit),
            'seats' => [
                ...$this->resource($seatsUsed, $seatLimit),
                'included' => $plan->seat_limit,
                'additional' => $additionalSeats,
            ],
            'ai_credits' => $this->resource($monthAiUsed, $plan->ai_credits_monthly),
            'boosts' => $this->resource($termBoostsUsed, $plan->job_boosts_per_term),
            'visa_credits' => [
                ...$this->resource($termVisaUsed, $plan->visa_credits_per_term),
                'purchased' => $purchasedVisa,
                'remaining' => $this->remaining($termVisaUsed, $plan->visa_credits_per_term)
                    + $purchasedVisa,
            ],
        ];
    }

    public function hasPortalAccess(Company $company): bool
    {
        return $company->status === CompanyStatus::Active
            && in_array($company->subscription_status, ['active', 'trialing', 'past_due'], true);
    }

    public function assertCanPublishJob(Company $company, ?JobPosting $job = null): void
    {
        $company->loadMissing('plan');

        if (! $this->hasPortalAccess($company)) {
            throw new DomainException(__('Das Firmenportal ist erst nach bestätigter Zahlung verfügbar.'));
        }

        if ($job?->status === JobStatus::Published) {
            return;
        }

        $limit = $company->plan?->active_jobs_limit;
        $used = JobPosting::query()
            ->where('company_id', $company->getKey())
            ->where('status', JobStatus::Published)
            ->count();

        if ($limit !== null && $used >= $limit) {
            throw new DomainException(__('Das Kontingent für aktive Stellenanzeigen ist ausgeschöpft.'));
        }
    }

    public function assertCanAddSeat(Company $company): void
    {
        $company->loadMissing('plan');
        $included = $company->plan?->seat_limit;
        $limit = $included === null
            ? null
            : $included + $this->additionalSeatLimit($company);
        $used = $company->memberships()->whereNotNull('accepted_at')->count();

        if ($limit !== null && $used >= $limit) {
            throw new DomainException(__('Das Sitzplatzkontingent ist ausgeschöpft.'));
        }
    }

    public function consumeAiCredits(Company $company, int $credits = 1): void
    {
        if ($credits < 1) {
            throw new DomainException(__('Der Credit-Verbrauch muss mindestens 1 betragen.'));
        }

        DB::transaction(function () use ($company, $credits): void {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::query()->with('plan')->lockForUpdate()->findOrFail((int) $company->getKey());
            $period = $this->monthlyUsage($lockedCompany, true);
            $limit = $lockedCompany->plan?->ai_credits_monthly;

            if ($lockedCompany->plan === null || $limit === 0 || ($limit !== null && $period->ai_credits_used + $credits > $limit)) {
                throw new DomainException(__('Für diesen Tarif sind nicht genügend KI-Credits verfügbar.'));
            }

            $period->increment('ai_credits_used', $credits);
        });
    }

    public function consumeBoost(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::query()->with('plan')->lockForUpdate()->findOrFail((int) $company->getKey());
            $period = $this->termUsage($lockedCompany, true);
            $limit = $lockedCompany->plan?->job_boosts_per_term;

            if ($lockedCompany->plan === null || ($limit !== null && $period->job_boosts_used >= $limit)) {
                throw new DomainException(__('Für diese Laufzeit ist kein Job-Boost mehr verfügbar.'));
            }

            $period->increment('job_boosts_used');
        });
    }

    public function consumeVisaCredit(Company $company, ?int $visaCaseId = null): void
    {
        DB::transaction(function () use ($company, $visaCaseId): void {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::query()->with('plan')->lockForUpdate()->findOrFail((int) $company->getKey());
            $period = $this->termUsage($lockedCompany, true);
            $limit = $lockedCompany->plan?->visa_credits_per_term;

            if ($lockedCompany->plan !== null && ($limit === null || $period->visa_credits_used < $limit)) {
                $period->increment('visa_credits_used');

                return;
            }

            $balance = $this->purchasedBalance($lockedCompany, 'visa', true);
            if ($balance < 1) {
                throw new DomainException(__('Es ist kein Visumpaket-Kontingent verfügbar.'));
            }

            EntitlementLedger::query()->create([
                'company_id' => $lockedCompany->getKey(),
                'resource' => 'visa',
                'amount' => -1,
                'source' => 'visa_case',
                'reference_type' => 'visa_case',
                'reference_id' => $visaCaseId,
            ]);
        });
    }

    /**
     * Add non-expiring credits after a verified one-time Stripe payment.
     */
    public function grantPurchasedVisaCredits(
        Company $company,
        int $credits,
        string $stripeReference,
    ): EntitlementLedger {
        if ($credits < 1) {
            throw new DomainException(__('Das Visumpaket muss mindestens einen Credit enthalten.'));
        }

        $existing = EntitlementLedger::query()
            ->where('company_id', $company->getKey())
            ->where('resource', 'visa')
            ->where('source', 'stripe_purchase')
            ->where('metadata->stripe_reference', $stripeReference)
            ->first();

        return $existing ?? EntitlementLedger::query()->create([
            'company_id' => $company->getKey(),
            'resource' => 'visa',
            'amount' => $credits,
            'source' => 'stripe_purchase',
            'reference_type' => 'stripe_payment',
            'metadata' => ['stripe_reference' => $stripeReference],
        ]);
    }

    private function monthlyUsage(Company $company, bool $lock): ?CompanyUsagePeriod
    {
        $start = now()->startOfMonth();
        $end = $start->copy()->addMonth();

        return $this->usagePeriod($company, $start, $end, $lock);
    }

    private function termUsage(Company $company, bool $lock): ?CompanyUsagePeriod
    {
        $start = $company->subscription_started_at ?? now()->startOfDay();
        $plan = $company->getRelation('plan');
        $termMonths = $plan instanceof Plan ? ($plan->term_months ?? 1) : 1;
        $end = $company->subscription_renews_at
            ?? $start->copy()->addMonths($termMonths);

        return $this->usagePeriod($company, $start, $end, $lock);
    }

    private function usagePeriod(
        Company $company,
        CarbonInterface $start,
        CarbonInterface $end,
        bool $create,
    ): ?CompanyUsagePeriod {
        $query = CompanyUsagePeriod::query()
            ->where('company_id', $company->getKey())
            ->where('starts_at', $start)
            ->where('ends_at', $end);

        if (! $create) {
            return $query->first();
        }

        return $query->lockForUpdate()->firstOrCreate([
            'company_id' => $company->getKey(),
            'plan_id' => $company->current_plan_id,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }

    private function purchasedBalance(Company $company, string $resource, bool $lock = false): int
    {
        $query = EntitlementLedger::query()
            ->where('company_id', $company->getKey())
            ->where('resource', $resource)
            ->where(fn ($builder) => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->sum('amount');
    }

    private function additionalSeatLimit(Company $company): int
    {
        $priceId = (string) config('services.stripe.seat_price_id');

        if ($priceId === '') {
            return 0;
        }

        $subscription = $company->subscription('default');

        if ($subscription === null) {
            return 0;
        }

        return max(
            0,
            (int) $subscription->items()
                ->where('stripe_price', $priceId)
                ->sum('quantity'),
        );
    }

    /**
     * @return array{used: int, limit: int|null, remaining: int|null}
     */
    private function resource(int $used, ?int $limit): array
    {
        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    private function remaining(int $used, ?int $limit): int
    {
        return $limit === null ? 0 : max(0, $limit - $used);
    }
}
