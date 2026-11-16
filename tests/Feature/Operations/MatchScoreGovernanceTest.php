<?php

use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\MatchScoreVersion;
use App\Models\User;
use App\Services\Matching\MatchScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function erinMatchWeights(int $profession = 25): array
{
    return [
        'profession' => $profession,
        'skills' => 20,
        'language' => 15,
        'experience' => 10,
        'employment' => 10,
        'availability' => 5,
        'salary' => 5,
        'relocation' => 5,
        'documents' => 100 - $profession - 70,
    ];
}

it('rejects invalid totals and protected match factors', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $invalid = erinMatchWeights();
    $invalid['skills'] = 99;

    $this->actingAs($admin)->post(route('admin.match-score-versions.store'), [
        'version' => '2.0-invalid',
        'weights' => $invalid,
    ])->assertSessionHasErrors('weights');

    $protected = erinMatchWeights();
    $protected['gender'] = 0;
    $this->actingAs($admin)->post(route('admin.match-score-versions.store'), [
        'version' => '2.0-protected',
        'weights' => $protected,
    ])->assertSessionHasErrors('weights');

    expect(MatchScoreVersion::query()->count())->toBe(0);
});

it('requires superadmin reauthentication reason and audit before activating a version', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $version = MatchScoreVersion::query()->create([
        'version' => '2.0', 'weights' => erinMatchWeights(), 'status' => 'draft',
    ]);

    $this->actingAs($support)->post(route('admin.match-score-versions.activate', $version), [
        'reason' => 'Approved fairness validation', 'confirmation' => 'ACTIVATE',
    ])->assertForbidden();

    $this->actingAs($admin)->post(route('admin.match-score-versions.activate', $version), [
        'reason' => 'Approved fairness validation', 'confirmation' => 'ACTIVATE',
    ])->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.match-score-versions.activate', $version), [
            'reason' => 'Approved fairness validation', 'confirmation' => 'ACTIVATE',
        ])->assertRedirect();

    expect($version->fresh())->status->toBe('active')
        ->activation_reason->toBe('Approved fairness validation');
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'admin.match_score_version.activated',
        'auditable_id' => $version->getKey(),
    ]);
});

it('is deterministic per active version and preserves historical application explanations', function () {
    $calculator = app(MatchScoreCalculator::class);
    $factors = array_fill_keys(array_keys(MatchScoreCalculator::WEIGHTS), 1);
    $v1 = $calculator->calculate($factors);
    $application = JobApplication::factory()->create([
        'match_score' => $v1['score'], 'match_breakdown' => $v1,
    ]);
    MatchScoreVersion::query()->create([
        'version' => '2.0', 'weights' => erinMatchWeights(30), 'status' => 'active', 'activated_at' => now(),
    ]);

    $first = $calculator->calculate($factors);
    $second = $calculator->calculate($factors);

    expect($first)->toBe($second)
        ->and($first['version'])->toBe('2.0')
        ->and($application->fresh()?->match_breakdown['version'])->toBe($v1['version'])
        ->and($application->fresh()?->match_breakdown['factors']['profession']['weight'])->toBe(25)
        ->and(array_keys($first['factors']))->not->toContain('gender', 'age', 'nationality', 'health');
});
