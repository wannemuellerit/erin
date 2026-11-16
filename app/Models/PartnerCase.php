<?php

namespace App\Models;

use App\Enums\PartnerServiceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property PartnerServiceType $service_type
 * @property int $candidate_user_id
 * @property int|null $company_id
 * @property int|null $partner_organization_id
 * @property int|null $partner_offering_id
 * @property int|null $assigned_member_id
 * @property string $target_country_code
 * @property string $status
 * @property string $public_status
 * @property string $purpose
 * @property Carbon|null $consented_at
 * @property Carbon|null $consent_expires_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $retention_until
 * @property Carbon|null $updated_at
 * @property-read PartnerOrganization|null $organization
 * @property-read PartnerOffering|null $offering
 */
class PartnerCase extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'service_type' => PartnerServiceType::class, 'requested_services' => 'array', 'service_details' => 'array',
            'shared_data_categories' => 'array', 'consented_at' => 'datetime',
            'consent_expires_at' => 'datetime', 'withdrawn_at' => 'datetime', 'retention_until' => 'datetime',
        ];
    }

    public function hasActiveConsent(): bool
    {
        return $this->consented_at !== null && $this->withdrawn_at === null
            && $this->consent_expires_at?->isFuture() === true;
    }

    /** @return BelongsTo<User, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_user_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PartnerOrganization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'partner_organization_id');
    }

    /** @return BelongsTo<PartnerOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(PartnerOffering::class, 'partner_offering_id');
    }

    /** @return BelongsTo<PartnerMember, $this> */
    public function assignedMember(): BelongsTo
    {
        return $this->belongsTo(PartnerMember::class, 'assigned_member_id');
    }

    /** @return HasMany<PartnerCaseArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(PartnerCaseArtifact::class);
    }

    /** @return HasMany<PartnerCaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PartnerCaseEvent::class);
    }

    /** @return HasMany<PartnerDocumentGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(PartnerDocumentGrant::class);
    }

    /** @return HasMany<PartnerCaseTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(PartnerCaseTask::class);
    }
}
