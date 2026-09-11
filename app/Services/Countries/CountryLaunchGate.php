<?php

namespace App\Services\Countries;

use App\Enums\CountryOperation;
use App\Models\CountryServiceRule;
use App\Models\FeatureFlag;

final class CountryLaunchGate
{
    public function enabled(CountryOperation $operation, string $countryCode): bool
    {
        $country = strtoupper($countryCode);
        $flag = FeatureFlag::query()->where('key', 'country_'.strtolower($country).'_'.$operation->value)->first();
        if ($flag === null || ! $flag->enabled || $flag->rollout_percentage !== 100) {
            return false;
        }

        return CountryServiceRule::query()->where('country_code', $country)->where('service_type', $operation->value)
            ->whereIn('status', ['pilot', 'enabled'])->whereNotNull('approved_at')
            ->whereRaw('version = (select max(r2.version) from country_service_rules r2 where r2.country_code = country_service_rules.country_code and r2.service_type = country_service_rules.service_type)')
            ->exists();
    }

    public function assertEnabled(CountryOperation $operation, string $countryCode): void
    {
        abort_unless($this->enabled($operation, $countryCode), 422, __('Diese Funktion ist im gewählten Zielland noch nicht rechtlich und operativ freigegeben.'));
    }
}
