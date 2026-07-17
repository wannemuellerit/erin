<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CompanyMemberRole $role
 */
class CompanyMembership extends Pivot
{
    public $incrementing = true;

    protected $table = 'company_memberships';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => CompanyMemberRole::class,
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsToMany<CompanyTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(CompanyTeam::class, 'company_team_members');
    }
}
