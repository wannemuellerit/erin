<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $name
 * @property string $slug
 * @property string $integration_mode
 * @property string $contract_status
 * @property string $dpa_status
 * @property array<int, string>|null $service_types
 * @property Carbon|null $legal_approved_at
 * @property Carbon|null $blocked_at
 */
class PartnerOrganization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'country_codes' => 'array', 'service_types' => 'array', 'languages' => 'array',
            'legal_approved_at' => 'datetime', 'logo_approved_at' => 'datetime', 'blocked_at' => 'datetime',
        ];
    }

    public function isOperational(): bool
    {
        return $this->blocked_at === null
            && $this->contract_status === 'approved'
            && $this->dpa_status === 'approved'
            && $this->legal_approved_at !== null;
    }

    /** @return HasMany<PartnerMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PartnerMember::class);
    }

    /** @return HasMany<PartnerOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(PartnerOffering::class);
    }
}
