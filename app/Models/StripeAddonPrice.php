<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string|null $stripe_product_id
 * @property string $stripe_price_id
 * @property bool $is_enabled
 */
class StripeAddonPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
