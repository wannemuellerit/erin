<?php

use App\Enums\UserRole;
use App\Models\AccessListEntry;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks login by normalized email without revealing the access rule', function () {
    $user = User::factory()->create([
        'email' => 'blocked@example.com',
        'password' => 'password',
    ]);
    $entry = AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => 'email',
        'value' => 'blocked@example.com',
        'reason' => 'Security incident',
    ]);

    $this->post(route('login.store'), [
        'email' => 'BLOCKED@EXAMPLE.COM',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(AuditLog::query()
        ->where('event', 'access.blocked.login')
        ->whereJsonContains('metadata->access_list_entry_id', $entry->getKey())
        ->exists())->toBeTrue()
        ->and($user->fresh())->not->toBeNull();
});

it('blocks registration atomically for domain and ip rules', function (string $type, string $value, array $server) {
    AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => $type,
        'value' => $value,
        'reason' => 'Registration protection',
    ]);

    $this->withServerVariables($server)->post(route('register.store'), [
        'name' => 'Blocked Candidate',
        'email' => 'candidate@sub.example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'candidate',
        'locale' => 'de',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'candidate@sub.example.com')->exists())->toBeFalse();
})->with([
    'parent domain' => ['domain', 'example.com', []],
    'canonical IPv6' => ['ip', '2001:db8::1', ['REMOTE_ADDR' => '2001:0db8:0:0:0:0:0:1']],
]);

it('lets a specific whitelist override a broader domain blacklist', function () {
    AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => 'domain',
        'value' => 'example.com',
        'reason' => 'Broad domain block',
    ]);
    AccessListEntry::query()->create([
        'list_type' => 'whitelist',
        'subject_type' => 'email',
        'value' => 'allowed@sub.example.com',
        'reason' => 'Verified exception',
    ]);
    $user = User::factory()->create([
        'email' => 'allowed@sub.example.com',
        'password' => 'password',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('ignores expired rules and ends an active session after a new block', function () {
    $user = User::factory()->create([
        'email' => 'active@example.com',
    ]);
    AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => 'email',
        'value' => $user->email,
        'reason' => 'Expired incident',
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $entry = AccessListEntry::query()->create([
        'list_type' => 'blacklist',
        'subject_type' => 'domain',
        'value' => 'example.com',
        'reason' => 'New global incident',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(AuditLog::query()
        ->where('event', 'access.blocked.active_session')
        ->whereJsonContains('metadata->access_list_entry_id', $entry->getKey())
        ->exists())->toBeTrue();
});

it('prevents an admin from blacklisting the last superadmin identity', function () {
    $admin = User::factory()->create([
        'email' => 'admin@wannemueller.dev',
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.access-list.store'), [
            'list_type' => 'blacklist',
            'subject_type' => 'domain',
            'value' => 'wannemueller.dev',
            'reason' => 'Unsafe administrative mistake',
        ])
        ->assertSessionHasErrors('value');

    expect(AccessListEntry::query()->exists())->toBeFalse();
});
