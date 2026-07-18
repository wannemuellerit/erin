<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription;

/**
 * @property int $id
 * @property int|null $current_plan_id
 * @property int|null $pending_plan_id
 * @property int|null $logo_media_id
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string|null $email
 * @property string|null $stripe_id
 * @property string|null $stripe_subscription_id
 * @property int $stripe_subscription_generation
 * @property int $stripe_next_subscription_generation
 * @property CompanyStatus $status
 * @property string|null $subscription_status
 * @property Carbon|null $subscription_started_at
 * @property Carbon|null $subscription_renews_at
 * @property Carbon|null $subscription_ends_at
 * @property Carbon|null $pending_plan_effective_at
 * @property bool $cancel_at_period_end
 * @property-read Plan|null $plan
 * @property-read Plan|null $pendingPlan
 * @property-read CompanyMedia|null $logoMedia
 * @property-read CompanyTrustMetric|null $trustMetric
 * @property-read Collection<int, BillingChangeIntent> $billingChangeIntents
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Billable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'stripe_subscription_generation' => 'integer',
            'stripe_next_subscription_generation' => 'integer',
            'benefits' => 'array',
            'branding' => 'array',
            'trial_ends_at' => 'datetime',
            'pending_plan_effective_at' => 'datetime',
            'subscription_started_at' => 'datetime',
            'subscription_renews_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'subscription_ends_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function stripeName(): ?string
    {
        return $this->legal_name ?: $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function stripeAddress(): array
    {
        return array_filter([
            'line1' => $this->address_line1,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country_code,
        ], fn (mixed $value): bool => filled($value));
    }

    /**
     * @return list<string>
     */
    public function stripePreferredLocales(): array
    {
        return ['de'];
    }

    /**
     * @return array<string, string>
     */
    public function stripeMetadata(): array
    {
        return [
            'erin_company_id' => (string) $this->getKey(),
            'erin_company_slug' => $this->slug,
        ];
    }

    public function billingSubscription(): ?Subscription
    {
        if ($this->stripe_subscription_id !== null) {
            /** @var Subscription|null $subscription */
            $subscription = $this->subscriptions()
                ->where('stripe_id', $this->stripe_subscription_id)
                ->first();

            return $subscription;
        }

        return $this->subscription('default');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /**
     * @return BelongsTo<CompanyMedia, $this>
     */
    public function logoMedia(): BelongsTo
    {
        return $this->belongsTo(CompanyMedia::class, 'logo_media_id');
    }

    /**
     * @return HasMany<CompanyMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(CompanyMedia::class);
    }

    /**
     * @return HasOne<CompanyTrustMetric, $this>
     */
    public function trustMetric(): HasOne
    {
        return $this->hasOne(CompanyTrustMetric::class);
    }

    /**
     * @return HasMany<CandidateInternalReview, $this>
     */
    public function candidateInternalReviews(): HasMany
    {
        return $this->hasMany(CandidateInternalReview::class);
    }

    /**
     * @return HasMany<CompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this, CompanyMembership, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_memberships')
            ->using(CompanyMembership::class)
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompanyLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(CompanyLocation::class);
    }

    /**
     * @return HasMany<CompanyTeam, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(CompanyTeam::class);
    }

    /**
     * @return HasMany<CompanyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    /**
     * @return HasMany<TalentList, $this>
     */
    public function talentLists(): HasMany
    {
        return $this->hasMany(TalentList::class);
    }

    /**
     * @return HasMany<CompanyUsagePeriod, $this>
     */
    public function usagePeriods(): HasMany
    {
        return $this->hasMany(CompanyUsagePeriod::class);
    }

    /**
     * @return HasMany<EntitlementLedger, $this>
     */
    public function entitlementLedger(): HasMany
    {
        return $this->hasMany(EntitlementLedger::class);
    }

    /**
     * @return HasMany<BillingChangeIntent, $this>
     */
    public function billingChangeIntents(): HasMany
    {
        return $this->hasMany(BillingChangeIntent::class);
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * @return HasMany<RecruiterReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(RecruiterReminder::class);
    }

    /**
     * @return HasMany<CandidateImport, $this>
     */
    public function candidateImports(): HasMany
    {
        return $this->hasMany(CandidateImport::class);
    }

    /**
     * @return HasMany<ActivityEntry, $this>
     */
    public function activityEntries(): HasMany
    {
        return $this->hasMany(ActivityEntry::class);
    }
}
