<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Occupation;
use App\Models\User;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DomainCatalogSeeder::class));

it('lets a superadmin create a candidate account with a prepared profile and mandatory password change', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $occupation = Occupation::query()->firstOrFail();

    $this->actingAs($admin)->post(route('admin.users.candidates.store'), [
        'first_name' => 'Ana',
        'last_name' => 'Ionescu',
        'email' => 'ANA@example.test',
        'temporary_password' => 'Start!Passwort2026',
        'email_verified' => true,
        'locale' => 'de',
        'current_country_code' => 'ro',
        'current_city' => 'Cluj',
        'phone' => '+401234567',
        'occupation_id' => $occupation->getKey(),
        'current_position' => 'Elektrikerin',
        'desired_position' => 'Industrieelektrikerin',
        'summary' => 'Erfahrene Fachkraft mit hoher Umzugsbereitschaft.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $candidate = User::query()->where('email', 'ana@example.test')->firstOrFail();
    expect($candidate->role)->toBe(UserRole::Candidate)
        ->and($candidate->email_verified_at)->not->toBeNull()
        ->and($candidate->password_change_required_at)->not->toBeNull()
        ->and(Hash::check('Start!Passwort2026', $candidate->password))->toBeTrue()
        ->and($candidate->candidateProfile?->current_country_code)->toBe('RO')
        ->and($candidate->candidateProfile?->occupation_id)->toBe($occupation->getKey())
        ->and(AuditLog::query()->where('event', 'admin.candidate_created')->where('auditable_id', $candidate->getKey())->exists())->toBeTrue();
});

it('rejects duplicate emails weak temporary passwords and malformed country codes', function (array $changes, string $error) {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    User::factory()->create(['email' => 'existing@example.test']);
    $payload = [
        'first_name' => 'Ana', 'last_name' => 'Ionescu', 'email' => 'new@example.test',
        'temporary_password' => 'Start!Passwort2026', 'email_verified' => false, 'locale' => 'de',
    ];

    $this->actingAs($admin)->post(route('admin.users.candidates.store'), [...$payload, ...$changes])
        ->assertSessionHasErrors($error);
})->with([
    'duplicate email' => [['email' => 'existing@example.test'], 'email'],
    'weak password' => [['temporary_password' => 'password'], 'temporary_password'],
    'invalid country code' => [['current_country_code' => 'ROU'], 'current_country_code'],
    'numeric country code' => [['current_country_code' => '12'], 'current_country_code'],
]);

it('keeps candidate creation unavailable to support company and candidate accounts', function (UserRole $role) {
    $actor = User::factory()->create(['role' => $role]);
    $this->actingAs($actor)->post(route('admin.users.candidates.store'), [
        'first_name' => 'Ana', 'last_name' => 'Ionescu', 'email' => 'ana@example.test',
        'temporary_password' => 'Start!Passwort2026', 'locale' => 'de',
    ])->assertForbidden();
})->with([UserRole::Support, UserRole::Company, UserRole::Candidate]);
