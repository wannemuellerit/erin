<?php

use App\Enums\UserRole;
use App\Models\AdminBootstrapInvitation;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates only a hashed short-lived one-time admin invitation', function () {
    $this->artisan('erin:bootstrap-admin', [
        '--email' => 'security@wannemueller.dev',
        '--name' => 'Security Admin',
        '--expires' => 30,
    ])->assertSuccessful();

    $invitation = AdminBootstrapInvitation::query()->firstOrFail();

    expect($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->token_hash)->not->toContain('security@')
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and(User::query()->where('email', 'security@wannemueller.dev')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'admin.bootstrap.invitation_created')->exists())->toBeTrue();
});

it('accepts an invitation once and requires password rotation and two factor setup', function () {
    $token = 'single-use-bootstrap-token';
    AdminBootstrapInvitation::query()->create([
        'email' => 'security@wannemueller.dev',
        'name' => 'Security Admin',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(30),
    ]);

    $response = $this->post(route('admin-bootstrap.store', $token), [
        'password' => 'Erin!SicheresStartpasswort2026',
        'password_confirmation' => 'Erin!SicheresStartpasswort2026',
    ]);

    $admin = User::query()->where('email', 'security@wannemueller.dev')->firstOrFail();

    $response->assertRedirect(route('security.edit'));
    expect($admin->role)->toBe(UserRole::SuperAdmin)
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->password_change_required_at)->not->toBeNull()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and(Hash::check('Erin!SicheresStartpasswort2026', $admin->password))->toBeTrue()
        ->and(AdminBootstrapInvitation::query()->firstOrFail()->used_at)->not->toBeNull();

    auth()->logout();

    $this->post(route('admin-bootstrap.store', $token), [
        'password' => 'Erin!NochEinStartpasswort2026',
        'password_confirmation' => 'Erin!NochEinStartpasswort2026',
    ])->assertGone();
});

it('rejects expired invitations and role changes without explicit force', function () {
    $candidate = User::factory()->create([
        'email' => 'candidate@wannemueller.dev',
        'role' => UserRole::Candidate,
    ]);
    $expiredToken = 'expired-bootstrap-token';
    AdminBootstrapInvitation::query()->create([
        'email' => 'expired@wannemueller.dev',
        'name' => 'Expired Admin',
        'token_hash' => hash('sha256', $expiredToken),
        'expires_at' => now()->subMinute(),
    ]);

    $this->get(route('admin-bootstrap.show', $expiredToken))->assertGone();

    $this->artisan('erin:bootstrap-admin', [
        '--email' => $candidate->email,
        '--name' => $candidate->name,
    ])->assertExitCode(1);

    expect($candidate->refresh()->role)->toBe(UserRole::Candidate);
});

it('clears the forced password change only after a successful password update', function () {
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'password' => 'Erin!SicheresStartpasswort2026',
        'password_change_required_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('user-password.update'), [
            'current_password' => 'Erin!SicheresStartpasswort2026',
            'password' => 'Erin!RotiertesStartpasswort2026',
            'password_confirmation' => 'Erin!RotiertesStartpasswort2026',
        ])
        ->assertSessionHasNoErrors();

    expect($admin->refresh()->password_change_required_at)->toBeNull()
        ->and(AuditLog::query()->where('event', 'security.password_updated')->exists())->toBeTrue();
});
