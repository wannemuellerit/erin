<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FeatureFlag;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('validates color syntax and contrast before publishing a theme', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $invalid = PlatformSettings::DEFAULT_COLORS;
    $invalid['text'] = '#FFFFFF';
    $invalid['background'] = '#FFFFFF';

    $this->actingAs($admin)
        ->patch(route('admin.settings.theme.update'), ['colors' => $invalid])
        ->assertSessionHasErrors('colors.text');

    $lowContrastPrimary = PlatformSettings::DEFAULT_COLORS;
    $lowContrastPrimary['primary'] = '#3B82F6';
    $lowContrastPrimary['primary_hover'] = '#60A5FA';

    $this->actingAs($admin)
        ->patch(route('admin.settings.theme.update'), ['colors' => $lowContrastPrimary])
        ->assertSessionHasErrors([
            'colors.primary',
            'colors.primary_hover',
        ]);

    $this->actingAs($admin)
        ->patch(route('admin.settings.theme.update'), [
            'colors' => PlatformSettings::DEFAULT_COLORS,
        ])
        ->assertRedirect();

    expect(PlatformSetting::query()->where('key', 'theme.colors')->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('event', 'admin.platform_settings.theme_updated')
            ->exists())->toBeTrue();
});

it('keeps billing add-ons disabled until a price is configured', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $payload = [
        'dashboard_notice' => [
            'enabled' => true,
            'title_de' => 'Gemeinsam gut ankommen',
            'title_en' => 'Arrive well together',
            'body_de' => 'Hinweis für Firmenkunden.',
            'body_en' => 'Notice for company customers.',
            'url' => null,
        ],
        'billing' => [
            'visa_credit_enabled' => true,
            'visa_credit_price_cents' => null,
            'seat_addon_enabled' => false,
            'seat_addon_price_cents' => null,
            'referral_commission_cents' => 25000,
        ],
    ];

    $this->actingAs($admin)
        ->patch(route('admin.settings.platform.update'), $payload)
        ->assertSessionHasErrors('billing.visa_credit_price_cents');

    $payload['billing']['visa_credit_price_cents'] = 149900;

    $this->actingAs($admin)
        ->patch(route('admin.settings.platform.update'), $payload)
        ->assertRedirect();

    expect(app(PlatformSettings::class)->get('billing.visa_credit_enabled'))->toBeTrue()
        ->and(app(PlatformSettings::class)->get('billing.visa_credit_price_cents'))->toBe(149900);
});

it('lets only superadmins manage feature flags and audits changes', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);

    $payload = [
        'key' => 'candidate.ai_studio',
        'name' => 'AI Studio',
        'description' => 'Kontrollierter Rollout',
        'enabled' => true,
        'rollout_percentage' => 25,
        'conditions' => ['locale' => ['de', 'en']],
    ];

    $this->actingAs($support)
        ->post(route('admin.feature-flags.store'), $payload)
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('admin.feature-flags.store'), $payload)
        ->assertRedirect();

    $flag = FeatureFlag::query()->where('key', 'candidate.ai_studio')->firstOrFail();
    expect($flag->enabled)->toBeTrue()
        ->and($flag->rollout_percentage)->toBe(25);

    $this->actingAs($admin)
        ->patch(route('admin.feature-flags.update', $flag), [
            'name' => 'AI Studio',
            'description' => 'Breiter Rollout',
            'enabled' => true,
            'rollout_percentage' => 100,
            'conditions' => null,
        ])
        ->assertRedirect();

    expect($flag->refresh()->rollout_percentage)->toBe(100)
        ->and(AuditLog::query()->where('event', 'admin.feature_flag.updated')->exists())->toBeTrue();
});
