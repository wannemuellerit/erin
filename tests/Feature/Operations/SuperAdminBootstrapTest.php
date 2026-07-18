<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses weak demo credentials for the production admin bootstrap', function () {
    $this->artisan('erin:bootstrap-admin', [
        '--email' => 'security@wannemueller.dev',
        '--name' => 'Security Admin',
        '--password' => 'password',
    ])->assertExitCode(1);

    expect(User::query()->where('email', 'security@wannemueller.dev')->exists())->toBeFalse();
});

it('creates a verified active superadmin with a strong initial secret', function () {
    $this->artisan('erin:bootstrap-admin', [
        '--email' => 'security@wannemueller.dev',
        '--name' => 'Security Admin',
        '--password' => 'Erin!SicheresStartpasswort2026',
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'security@wannemueller.dev')->firstOrFail();

    expect($admin->role)->toBe(UserRole::SuperAdmin)
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->onboarding_completed_at)->not->toBeNull();
});

it('does not silently elevate an existing non-admin identity', function () {
    User::factory()->create([
        'email' => 'candidate@wannemueller.dev',
        'role' => UserRole::Candidate,
    ]);

    $this->artisan('erin:bootstrap-admin', [
        '--email' => 'candidate@wannemueller.dev',
        '--name' => 'Candidate',
        '--password' => 'Erin!SicheresStartpasswort2026',
    ])->assertExitCode(1);

    expect(User::query()->where('email', 'candidate@wannemueller.dev')->firstOrFail()->role)
        ->toBe(UserRole::Candidate);
});
