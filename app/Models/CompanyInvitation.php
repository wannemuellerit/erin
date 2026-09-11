<?php

namespace App\Models;

use App\Enums\CompanyMemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $invited_by
 * @property string $email
 * @property CompanyMemberRole $role
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property-read Company $company
 */
class CompanyInvitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => CompanyMemberRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected $appends = ['status'];

    public function getStatusAttribute(): string
    {
        if ($this->accepted_at !== null) {
            return 'accepted';
        }
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'pending';
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
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
