<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $stripe_product_id
 * @property string $stripe_price_id
 * @property int $price_cents
 * @property string $currency
 * @property int $term_months
 * @property string $tax_behavior
 * @property string|null $tax_code
 * @property array<string, mixed>|null $plan_snapshot
 * @property string $version_hash
 * @property string $source
 * @property bool $is_current
 * @property-read Plan $plan
 */
class PlanStripePrice extends Model
{
    /** @var list<string> */
    private const IMMUTABLE_ATTRIBUTES = [
        'plan_id',
        'stripe_product_id',
        'stripe_price_id',
        'price_cents',
        'currency',
        'term_months',
        'tax_behavior',
        'tax_code',
        'plan_snapshot',
        'version_hash',
        'source',
        'activated_at',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'price_cents' => 'integer',
            'term_months' => 'integer',
            'plan_snapshot' => 'array',
            'is_current' => 'boolean',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PlanStripePrice $price): void {
            if ($price->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new LogicException(
                    'Eine Stripe-Preisversion ist unveränderlich.',
                );
            }
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Historische Stripe-Preisversionen dürfen nicht gelöscht werden.',
            );
        });
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
