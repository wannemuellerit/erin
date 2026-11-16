<?php

use App\Enums\CountryOperation;
use App\Models\CountryServiceRule;
use App\Models\FeatureFlag;
use App\Services\Countries\CountryLaunchGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fails closed unless the country operation has both a full flag and legal approval', function () {
    $gate = app(CountryLaunchGate::class);
    expect($gate->enabled(CountryOperation::Recruiting, 'PL'))->toBeFalse();

    FeatureFlag::query()->create(['key' => 'country_pl_recruiting', 'name' => 'Poland recruiting', 'enabled' => true, 'rollout_percentage' => 50]);
    CountryServiceRule::query()->create(['country_code' => 'PL', 'service_type' => 'recruiting', 'status' => 'pilot', 'legal_basis' => 'Reviewed basis', 'version' => 1]);
    expect($gate->enabled(CountryOperation::Recruiting, 'PL'))->toBeFalse();

    FeatureFlag::query()->where('key', 'country_pl_recruiting')->update(['rollout_percentage' => 100]);
    CountryServiceRule::query()->where('country_code', 'PL')->update(['approved_at' => now()]);
    expect($gate->enabled(CountryOperation::Recruiting, 'pl'))->toBeTrue();
});

it('uses only the latest rule version and lets a legal rollback stop a launch immediately', function () {
    FeatureFlag::query()->create(['key' => 'country_es_billing', 'name' => 'Spain billing', 'enabled' => true, 'rollout_percentage' => 100]);
    CountryServiceRule::query()->create(['country_code' => 'ES', 'service_type' => 'billing', 'status' => 'enabled', 'legal_basis' => 'First approval', 'version' => 1, 'approved_at' => now()]);
    expect(app(CountryLaunchGate::class)->enabled(CountryOperation::Billing, 'ES'))->toBeTrue();

    CountryServiceRule::query()->create(['country_code' => 'ES', 'service_type' => 'billing', 'status' => 'disabled', 'legal_basis' => 'Legal rollback', 'version' => 2]);
    expect(app(CountryLaunchGate::class)->enabled(CountryOperation::Billing, 'ES'))->toBeFalse();
});

it('keeps recruiting billing visa relocation and every partner vertical separate', function () {
    expect(CountryOperation::values())->toEqualCanonicalizing([
        'recruiting', 'employment_contract', 'billing', 'visa', 'relocation', 'language_course', 'recognition', 'translation',
        'health_insurance', 'housing', 'travel', 'tax', 'bank', 'connectivity', 'payroll',
    ]);
});
