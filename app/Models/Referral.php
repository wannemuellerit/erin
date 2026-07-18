<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use App\Observers\ReferralObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([ReferralObserver::class])]
class Referral extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'clicked_at' => 'datetime',
            'registered_at' => 'datetime',
            'hired_at' => 'datetime',
            'hold_until' => 'datetime',
            'approval_notified_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ReferralCode, $this>
     */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    /**
     * @return HasMany<ReferralStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReferralStatusHistory::class)->oldest('created_at');
    }
}
