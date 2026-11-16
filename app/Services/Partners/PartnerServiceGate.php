<?php

namespace App\Services\Partners;

use App\Enums\PartnerServiceType;
use App\Models\CountryServiceRule;
use App\Models\FeatureFlag;
use App\Models\PartnerOffering;

final class PartnerServiceGate
{
    public function isEnabled(PartnerServiceType $service, string $countryCode): bool
    {
        $flag = FeatureFlag::query()->where('key', 'partner_'.$service->value)->first();
        if ($flag === null || ! $flag->enabled) {
            return false;
        }

        return CountryServiceRule::query()
            ->where('country_code', strtoupper($countryCode))
            ->where('service_type', $service->value)
            ->whereIn('status', ['pilot', 'enabled'])
            ->whereNotNull('approved_at')
            ->whereRaw('version = (select max(r2.version) from country_service_rules r2 where r2.country_code = country_service_rules.country_code and r2.service_type = country_service_rules.service_type)')
            ->exists();
    }

    public function offeringIsAvailable(PartnerOffering $offering): bool
    {
        return $offering->is_active
            && ($offering->valid_from === null || $offering->valid_from->isPast())
            && ($offering->valid_until === null || $offering->valid_until->isFuture())
            && $offering->organization->isOperational()
            && $this->isEnabled($offering->service_type, $offering->country_code);
    }
}
