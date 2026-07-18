<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $from_plan_id
 * @property int $to_plan_id
 * @property int|null $requested_by
 * @property string $change_type
 * @property string $status
 * @property string|null $active_company_key
 * @property string $stripe_subscription_id
 * @property string $from_stripe_price_id
 * @property string $to_stripe_price_id
 * @property string $stripe_idempotency_key
 * @property array<string, mixed>|null $context
 * @property int $attempts
 * @property-read Company $company
 * @property-read Plan $fromPlan
 * @property-read Plan $toPlan
 */
class BillingChangeIntent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'attempts' => 'integer',
            'effective_at' => 'datetime',
            'applied_at' => 'datetime',
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
     * @return BelongsTo<Plan, $this>
     */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }
}
