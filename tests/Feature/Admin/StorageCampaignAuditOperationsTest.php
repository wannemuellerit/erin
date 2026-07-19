<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CandidateProfile;
use App\Models\SecurityAlert;
use App\Models\UploadReservation;
use App\Models\User;
use App\Services\Audit\SecurityAnomalyDetector;
use App\Services\Documents\UploadPolicy;
use App\Services\Platform\DashboardAdCampaignManager;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('reserves storage atomically and rejects capacity already reserved by another upload', function () {
    $user = User::factory()->create(['storage_quota_bytes' => 10 * 1024 * 1024]);
    $policy = app(UploadPolicy::class);

    $reservation = $policy->assertCanStore(
        $user,
        UploadedFile::fake()->create('first.pdf', 6 * 1024),
    );

    expect($reservation)->toBeInstanceOf(UploadReservation::class)
        ->and($policy->usageFor($user)['reserved_bytes'])->toBeGreaterThan(0);

    expect(fn () => $policy->assertCanStore(
        $user,
        UploadedFile::fake()->create('second.pdf', 6 * 1024),
    ))->toThrow(ValidationException::class);

    $reservation->delete();
    expect(fn () => $policy->assertCanStore(
        $user,
        UploadedFile::fake()->create('second.pdf', 6 * 1024),
    ))->not->toThrow(ValidationException::class);
});

it('allows only superadmins to set and reset individual storage quotas', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $candidate = User::factory()->create();

    $this->actingAs($support)
        ->patch(route('admin.users.storage-quota.update', $candidate), ['storage_quota_mb' => 2048])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('admin.users.storage-quota.update', $candidate), ['storage_quota_mb' => 2048])
        ->assertRedirect();
    expect($candidate->refresh()->storage_quota_bytes)->toBe(2048 * 1024 * 1024);

    $this->actingAs($admin)
        ->patch(route('admin.users.storage-quota.update', $candidate), ['storage_quota_mb' => null])
        ->assertRedirect();
    expect($candidate->refresh()->storage_quota_bytes)->toBeNull();
});

it('keeps referenced files and removes only sufficiently old orphan files', function () {
    Storage::fake('private');
    $settings = app(PlatformSettings::class);
    $settings->put('retention.orphan_grace_hours', 24);
    Storage::disk('private')->put('orphan.txt', 'orphan');
    Storage::disk('private')->put('referenced.txt', 'reference');
    touch(Storage::disk('private')->path('orphan.txt'), now()->subDays(2)->getTimestamp());
    touch(Storage::disk('private')->path('referenced.txt'), now()->subDays(2)->getTimestamp());

    $user = User::factory()->create();
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'profile_photo_path' => 'referenced.txt',
    ]);
    expect($profile->exists)->toBeTrue();

    $this->artisan('erin:storage:prune', ['--execute' => true, '--json' => true])
        ->assertSuccessful();

    Storage::disk('private')->assertMissing('orphan.txt');
    Storage::disk('private')->assertExists('referenced.txt');
});

it('tracks ad impressions and clicks while rejecting disabled campaigns', function () {
    $user = User::factory()->create();
    $campaign = app(DashboardAdCampaignManager::class)->sync([
        'campaign_name' => 'Sommerkampagne',
        'audience' => 'all',
        'title_de' => 'Titel',
        'title_en' => 'Title',
        'body_de' => 'Text',
        'body_en' => 'Copy',
        'cta_label_de' => 'Öffnen',
        'cta_label_en' => 'Open',
        'url' => 'https://example.com',
        'enabled' => true,
        'starts_at' => null,
        'ends_at' => null,
    ], null);

    $this->actingAs($user)->post(route('ads.impression', $campaign))->assertNoContent();
    $this->actingAs($user)->post(route('ads.impression', $campaign))->assertNoContent();
    $this->actingAs($user)->post(route('ads.click', $campaign))->assertNoContent();

    expect(app(DashboardAdCampaignManager::class)->statistics($campaign->id))
        ->toMatchArray(['impressions' => 2, 'clicks' => 1, 'ctr' => 50.0]);

    $campaign->update(['enabled' => false]);
    $this->actingAs($user)->post(route('ads.click', $campaign))->assertNotFound();
});

it('exports audit data without change payloads and blocks support exports', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    AuditLog::query()->create([
        'actor_id' => $admin->id,
        'event' => '=FORMULA',
        'after_values' => ['secret' => 'must-not-be-exported'],
        'created_at' => now(),
    ]);

    $this->actingAs($support)->get(route('admin.audit.export'))->assertForbidden();
    $response = $this->actingAs($admin)->get(route('admin.audit.export'))->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain("'=FORMULA")
        ->not->toContain('must-not-be-exported');
});

it('preserves retention-locked audit logs while pruning unlocked records', function () {
    app(PlatformSettings::class)->put('retention.audit_log_days', 30);
    $locked = AuditLog::query()->create([
        'event' => 'locked',
        'created_at' => now()->subDays(90),
        'retention_locked_at' => now(),
        'retention_lock_reason' => 'Rechtliche Aufbewahrung',
    ]);
    $unlocked = AuditLog::query()->create([
        'event' => 'unlocked',
        'created_at' => now()->subDays(90),
    ]);

    $this->artisan('erin:audit:prune', ['--execute' => true, '--json' => true])
        ->assertSuccessful();

    expect(AuditLog::query()->find($locked->id))->not->toBeNull()
        ->and(AuditLog::query()->find($unlocked->id))->toBeNull();
});

it('detects bursts once, updates recurring alerts and allows resolution', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $actor = User::factory()->create();
    foreach (range(1, 5) as $index) {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'event' => 'user.action_performed',
            'metadata' => ['response_status' => 403, 'route' => "restricted.{$index}"],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    $detector = app(SecurityAnomalyDetector::class);
    $detector->detect();
    $detector->detect();
    $alert = SecurityAlert::query()->where('type', 'denied_request_burst')->firstOrFail();
    expect(SecurityAlert::query()->where('type', 'denied_request_burst')->count())->toBe(1)
        ->and($alert->occurrences)->toBe(2);

    $this->actingAs($admin)
        ->patch(route('admin.audit.alerts.resolve', $alert))
        ->assertRedirect();
    expect($alert->refresh()->status)->toBe('resolved');
});
