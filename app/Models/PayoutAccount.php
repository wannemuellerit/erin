<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $external_account_id @property string $status @property string $kyc_status @property string $currency_code @property \Illuminate\Support\Carbon|null $disabled_at */
class PayoutAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['external_account_id' => 'encrypted', 'terms_accepted_at' => 'datetime', 'verified_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function isPayable(): bool
    {
        return $this->status === 'active' && $this->kyc_status === 'verified' && $this->disabled_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ReferralPayoutIntent, $this> */
    public function payoutIntents(): HasMany
    {
        return $this->hasMany(ReferralPayoutIntent::class);
    }
}
