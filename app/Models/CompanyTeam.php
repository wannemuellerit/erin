<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyTeam extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CompanyLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(CompanyLocation::class, 'location_id');
    }

    /** @return BelongsTo<CompanyMembership, $this> */
    public function contactMembership(): BelongsTo
    {
        return $this->belongsTo(CompanyMembership::class, 'contact_membership_id');
    }

    /**
     * @return BelongsToMany<CompanyMembership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyMembership::class,
            'company_team_members',
            'company_team_id',
            'company_membership_id',
            'id',
            'id',
        );
    }
}
