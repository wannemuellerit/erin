<?php

namespace App\Models;

use App\Enums\PartnerServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property PartnerServiceType $service_type
 * @property int $partner_organization_id
 * @property string $country_code
 * @property string $title
 * @property string|null $description
 * @property array<string, mixed>|null $attributes
 * @property string|null $currency_code
 * @property int|null $price_minor
 * @property int $version
 * @property bool $is_active
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property-read PartnerOrganization $organization
 */
class PartnerOffering extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service_type' => PartnerServiceType::class, 'attributes' => 'array', 'is_active' => 'boolean',
            'valid_from' => 'datetime', 'valid_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<PartnerOrganization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'partner_organization_id');
    }
}
