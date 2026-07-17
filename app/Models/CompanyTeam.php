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

    /**
     * @return BelongsToMany<CompanyMembership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(CompanyMembership::class, 'company_team_members');
    }
}
