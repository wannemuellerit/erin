<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property UserRole $role
 * @property UserStatus $status
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $last_active_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $onboarding_completed_at
 * @property Carbon|null $password_change_required_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CandidateProfile|null $candidateProfile
 */
#[Fillable(['name', 'email', 'password', 'role', 'status', 'locale', 'timezone', 'onboarding_completed_at', 'password_change_required_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return HasOne<CandidateProfile, $this>
     */
    public function candidateProfile(): HasOne
    {
        return $this->hasOne(CandidateProfile::class);
    }

    /**
     * @return HasMany<CompanyMembership, $this>
     */
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /**
     * @return BelongsToMany<Company, $this, CompanyMembership, 'pivot'>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_memberships')
            ->using(CompanyMembership::class)
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<UserAvailabilitySlot, $this>
     */
    public function availabilitySlots(): HasMany
    {
        return $this->hasMany(UserAvailabilitySlot::class);
    }

    /**
     * @return HasMany<ReferralCode, $this>
     */
    public function referralCodes(): HasMany
    {
        return $this->hasMany(ReferralCode::class);
    }

    /**
     * @return HasMany<SupportTicket, $this>
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'requester_id');
    }

    /**
     * @return HasMany<RecruiterReminder, $this>
     */
    public function assignedReminders(): HasMany
    {
        return $this->hasMany(RecruiterReminder::class, 'assignee_id');
    }

    /**
     * @return HasMany<ActivityEntry, $this>
     */
    public function activityEntries(): HasMany
    {
        return $this->hasMany(ActivityEntry::class, 'subject_user_id');
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * @return HasMany<AiConsent, $this>
     */
    public function aiConsents(): HasMany
    {
        return $this->hasMany(AiConsent::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isSupport(): bool
    {
        return $this->role === UserRole::Support;
    }

    public function isPlatformStaff(): bool
    {
        return $this->isSuperAdmin() || $this->isSupport();
    }

    public function belongsToCompany(Company|int $company): bool
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $this->companyMemberships()
            ->where('company_id', $companyId)
            ->whereNotNull('accepted_at')
            ->exists();
    }

    /**
     * @param  array<CompanyMemberRole>  $roles
     */
    public function hasCompanyRole(Company|int $company, array $roles): bool
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $this->companyMemberships()
            ->where('company_id', $companyId)
            ->whereIn('role', array_map(
                static fn (CompanyMemberRole $role): string => $role->value,
                $roles,
            ))
            ->whereNotNull('accepted_at')
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'last_active_at' => 'datetime',
            'suspended_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'password_change_required_at' => 'datetime',
            /* @chisel-2fa */
            'two_factor_confirmed_at' => 'datetime',
            /* @end-chisel-2fa */
        ];
    }
}
