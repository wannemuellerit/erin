<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $stripe_product_id
 * @property string $stripe_price_id
 * @property int $price_cents
 * @property string $currency
 * @property int $term_months
 * @property string $version_hash
 * @property string $source
 * @property bool $is_current
 * @property-read Plan $plan
 */
class PlanStripePrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'term_months' => 'integer',
            'is_current' => 'boolean',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
