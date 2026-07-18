<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $company_id
 * @property string $resource
 * @property int $amount
 * @property string $source
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<string, mixed>|null $metadata
 * @property-read string|null $stripe_payment_intent_id
 */
class EntitlementLedger extends Model
{
    protected $guarded = ['id', 'stripe_payment_intent_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'metadata' => 'array'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
