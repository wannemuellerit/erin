<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks user flows while health, status and platform staff remain available', function () {
    app(PlatformSettings::class)->put('platform.maintenance', [
        'active' => true,
        'message_de' => 'Geplante Wartung',
        'message_en' => 'Planned maintenance',
        'expected_end_at' => now()->addHour()->toIso8601String(),
    ], 'operations', true);
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->get(route('home'))
        ->assertServiceUnavailable()
        ->assertHeader('Retry-After', '300')
        ->assertSee('Geplante Wartung');
    $this->actingAs($candidate)
        ->get(route('dashboard'))
        ->assertServiceUnavailable();

    $this->get('/up')->assertOk();
    $this->get(route('status'))
        ->assertOk()
        ->assertSee('Wartung läuft');
    $this->actingAs($admin)
        ->get(route('admin.system.index'))
        ->assertOk();
});

it('activates and deactivates maintenance through an audited superadmin action', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $candidate = User::factory()->create([
        'role' => UserRole::Candidate,
        'locale' => 'pl',
    ]);
    $translations = collect(config('app.supported_locales'))
        ->mapWithKeys(fn (string $locale): array => [$locale => "Maintenance {$locale}"])
        ->all();

    $this->actingAs($admin)
        ->patch(route('admin.maintenance.update'), [
            'active' => true,
            'translations' => $translations,
            'expected_end_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertRedirect();

    expect(app(PlatformSettings::class)->get('platform.maintenance')['active'])
        ->toBeTrue()
        ->and(AuditLog::query()->where('event', 'admin.maintenance.activated')->exists())
        ->toBeTrue();
    $this->actingAs($candidate)->get(route('dashboard'))->assertServiceUnavailable();
    $this->withSession(['locale' => 'pl'])
        ->get(route('home'))
        ->assertServiceUnavailable()
        ->assertSee('Maintenance pl');

    $this->actingAs($admin)
        ->patch(route('admin.maintenance.update'), [
            'active' => false,
            'expected_end_at' => null,
        ])
        ->assertRedirect();

    $this->actingAs($candidate)->get(route('dashboard'))->assertOk();
    expect(AuditLog::query()->where('event', 'admin.maintenance.deactivated')->exists())
        ->toBeTrue();
});
