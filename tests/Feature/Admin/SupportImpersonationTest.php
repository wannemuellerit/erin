<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires a reason and keeps support impersonation read only', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $target = User::factory()->create([
        'role' => UserRole::Candidate,
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($support)
        ->post(route('admin.support.impersonation.start', $target), ['reason' => 'too short'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($support)
        ->post(route('admin.support.impersonation.start', $target), [
            'reason' => 'Fehler im Kandidatendashboard aus Nutzersicht nachvollziehen',
        ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    $this->assertTrue(session()->has('impersonation_session_id'));

    $this->post(route('logout'))->assertForbidden();

    $this->post(route('support.impersonation.stop'))
        ->assertRedirect(route('admin.support.index'));

    $this->assertAuthenticatedAs($support);
    $this->assertFalse(session()->has('impersonation_session_id'));

    $impersonation = ImpersonationSession::query()->firstOrFail();
    expect($impersonation->actor_id)->toBe($support->id)
        ->and($impersonation->target_id)->toBe($target->id)
        ->and($impersonation->mode)->toBe('read_only')
        ->and($impersonation->ended_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'support.impersonation.started')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'support.impersonation.ended')->exists())->toBeTrue();
});

it('does not allow platform staff or blocked users as impersonation targets', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $otherSupport = User::factory()->create(['role' => UserRole::Support]);
    $blocked = User::factory()->create(['status' => UserStatus::Blocked]);
    $reason = ['reason' => 'Supportfall mit ausreichender und nachvollziehbarer Begründung'];

    $this->actingAs($support)
        ->post(route('admin.support.impersonation.start', $otherSupport), $reason)
        ->assertSessionHasErrors('reason');

    $this->actingAs($support)
        ->post(route('admin.support.impersonation.start', $blocked), $reason)
        ->assertSessionHasErrors('reason');

    expect(ImpersonationSession::query()->count())->toBe(0);
});
