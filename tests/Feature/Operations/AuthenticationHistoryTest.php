<?php

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records successful logins with request context and no credentials', function () {
    $user = User::factory()->create([
        'email' => 'login-history@wannemueller.dev',
        'password' => 'password',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('User-Agent', 'Erin Security Test/1.0')
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard'));

    $history = LoginHistory::query()->sole();

    expect($history->user_id)->toBe($user->id)
        ->and($history->email)->toBe($user->email)
        ->and($history->event)->toBe('login')
        ->and($history->successful)->toBeTrue()
        ->and($history->ip_address)->toBe('203.0.113.10')
        ->and($history->user_agent)->toBe('Erin Security Test/1.0')
        ->and($history->failure_reason)->toBeNull()
        ->and(json_encode($history->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('password');
});

it('records failed logins without persisting the supplied password', function () {
    $user = User::factory()->create([
        'email' => 'failed-login@wannemueller.dev',
        'password' => 'password',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->withHeader('User-Agent', 'Erin Security Test/2.0')
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'definitely-not-the-password',
        ])
        ->assertSessionHasErrors('email');

    $history = LoginHistory::query()->sole();
    $serialized = json_encode($history->getAttributes(), JSON_THROW_ON_ERROR);

    expect($history->user_id)->toBe($user->id)
        ->and($history->event)->toBe('failed')
        ->and($history->successful)->toBeFalse()
        ->and($history->failure_reason)->toBe('invalid_credentials')
        ->and($history->ip_address)->toBe('203.0.113.11')
        ->and($serialized)->not->toContain('definitely-not-the-password');
});

it('records logout events separately from successful logins', function () {
    $user = User::factory()->create([
        'email' => 'logout-history@wannemueller.dev',
        'password' => 'password',
    ]);

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->withHeader('User-Agent', 'Erin Security Test/3.0')
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $history = LoginHistory::query()->sole();

    expect($history->user_id)->toBe($user->id)
        ->and($history->event)->toBe('logout')
        ->and($history->successful)->toBeTrue()
        ->and($history->failure_reason)->toBeNull()
        ->and($history->ip_address)->toBe('203.0.113.12');
});
