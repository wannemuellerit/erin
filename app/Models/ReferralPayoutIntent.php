<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $referral_id
 * @property int|null $payout_account_id
 * @property int $amount_cents
 * @property string $currency_code
 * @property string $status
 * @property int $fraud_score
 * @property array<int, string>|null $fraud_signals
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property Carbon|null $approved_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $paid_at
 */
class ReferralPayoutIntent extends Model
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
        return ['fraud_signals' => 'array', 'approved_at' => 'datetime', 'submitted_at' => 'datetime', 'paid_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<PayoutAccount, $this> */
    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }
}
