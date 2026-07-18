<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int|null $price_cents
 * @property string $currency
 * @property int|null $term_months
 * @property int|null $active_jobs_limit
 * @property int|null $seat_limit
 * @property int|null $ai_credits_monthly
 * @property int|null $job_boosts_per_term
 * @property int|null $visa_credits_per_term
 * @property bool $is_enterprise
 * @property bool $is_active
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_id
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_enterprise' => 'boolean',
            'is_active' => 'boolean',
            'price_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlanEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'current_plan_id');
    }

    public function isUnlimited(string $attribute): bool
    {
        return $this->{$attribute} === null;
    }
}
