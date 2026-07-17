<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restricts the admin area to platform staff', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $support = User::factory()->create(['role' => UserRole::Support]);

    $this->get('/admin')->assertRedirect(route('login'));
    $this->actingAs($candidate)->get('/admin')->assertForbidden();
    $this->actingAs($support)->get('/admin/users')->assertOk();
});

it('lets only superadmins block and unblock users and records both changes', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $target = User::factory()->create();

    $this->actingAs($support)
        ->patch(route('admin.users.status.update', $target), [
            'status' => UserStatus::Blocked->value,
            'reason' => 'Wiederholter Regelverstoß',
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('admin.users.status.update', $target), [
            'status' => UserStatus::Blocked->value,
            'reason' => 'Wiederholter Regelverstoß',
        ])
        ->assertRedirect();

    expect($target->refresh()->status)->toBe(UserStatus::Blocked)
        ->and($target->blocked_reason)->toBe('Wiederholter Regelverstoß')
        ->and($target->suspended_at)->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('admin.users.status.update', $target), [
            'status' => UserStatus::Active->value,
        ])
        ->assertRedirect();

    expect($target->refresh()->status)->toBe(UserStatus::Active)
        ->and($target->blocked_reason)->toBeNull()
        ->and($target->suspended_at)->toBeNull()
        ->and(AuditLog::query()
            ->where('event', 'admin.user.status_updated')
            ->where('auditable_id', $target->id)
            ->count())->toBe(2);
});

it('prevents self lockout and protects the last active superadmin', function () {
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.users.status.update', $admin), [
            'status' => UserStatus::Blocked->value,
            'reason' => 'Should not be accepted',
        ])
        ->assertSessionHasErrors('status');

    $this->actingAs($admin)
        ->patch(route('admin.users.role.update', $admin), [
            'role' => UserRole::Support->value,
        ])
        ->assertSessionHasErrors('role');

    expect($admin->refresh()->role)->toBe(UserRole::SuperAdmin)
        ->and($admin->status)->toBe(UserStatus::Active);
});

it('lets superadmins block companies with a documented reason', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $company = Company::factory()->create(['status' => CompanyStatus::Active]);

    $this->actingAs($admin)
        ->patch(route('admin.companies.status.update', $company), [
            'status' => CompanyStatus::Blocked->value,
            'reason' => 'Verifizierter Plattformmissbrauch',
        ])
        ->assertRedirect();

    expect($company->refresh()->status)->toBe(CompanyStatus::Blocked)
        ->and(AuditLog::query()
            ->where('event', 'admin.company.status_updated')
            ->where('auditable_id', $company->id)
            ->exists())->toBeTrue();
});
