<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $stripe_invoice_id
 * @property string|null $number
 * @property string $status
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property int $amount_paid_cents
 * @property int $amount_due_cents
 * @property array<int, string>|null $promotion_codes
 * @property array<int, array<string, mixed>>|null $customer_tax_ids
 * @property array<string, mixed>|null $billing_address
 * @property int $stripe_event_created_at
 * @property Carbon|null $period_starts_at
 * @property Carbon|null $period_ends_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $paid_at
 * @property-read Company $company
 */
class CompanyBillingInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'amount_due_cents' => 'integer',
            'promotion_codes' => 'array',
            'customer_tax_ids' => 'array',
            'billing_address' => 'array',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'stripe_event_created_at' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
