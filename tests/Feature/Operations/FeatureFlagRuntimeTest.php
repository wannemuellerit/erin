<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Platform\FeatureFlagResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(FeatureFlagResolver::class)->forget();
});

function featureRequest(User $user, ?Company $company = null, string $sessionId = 'feature-session'): Request
{
    $request = Request::create('/feature-probe');
    $session = new Store($sessionId, new ArraySessionHandler(120));

    if ($company !== null) {
        $session->put('active_company_id', $company->getKey());
    }

    $request->setLaravelSession($session);
    $request->setUserResolver(fn (): User => $user);

    return $request;
}

it('enforces feature kill switches in the backend and invalidates cached decisions', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $flag = FeatureFlag::query()->create([
        'key' => 'ai',
        'name' => 'AI',
        'enabled' => false,
        'rollout_percentage' => 100,
    ]);
    app(FeatureFlagResolver::class)->forget();

    $this->actingAs($candidate)
        ->get(route('candidate.ai-studio'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->patch(route('admin.feature-flags.update', $flag), [
            'name' => 'AI',
            'description' => 'Re-enabled without deployment',
            'enabled' => true,
            'rollout_percentage' => 100,
            'conditions' => null,
        ])
        ->assertRedirect();

    $this->actingAs($candidate)
        ->get(route('candidate.ai-studio'))
        ->assertOk();
});

it('matches role, company, user and activation windows together', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $other = User::factory()->create(['role' => UserRole::Candidate]);
    $company = Company::factory()->create();
    $flag = FeatureFlag::query()->create([
        'key' => 'targeted-pilot',
        'name' => 'Targeted pilot',
        'enabled' => true,
        'rollout_percentage' => 100,
        'conditions' => [
            'roles' => ['candidate'],
            'user_ids' => [$candidate->getKey()],
            'company_ids' => [$company->getKey()],
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addMinute()->toIso8601String(),
        ],
    ]);
    $resolver = app(FeatureFlagResolver::class);
    $resolver->forget();

    expect($resolver->enabledForRequest($flag->key, featureRequest($candidate, $company)))
        ->toBeTrue()
        ->and($resolver->enabledForRequest($flag->key, featureRequest($other, $company)))
        ->toBeFalse();

    $flag->update([
        'conditions' => [
            'roles' => ['candidate'],
            'user_ids' => [$candidate->getKey()],
            'company_ids' => [$company->getKey()],
            'ends_at' => now()->subSecond()->toIso8601String(),
        ],
    ]);
    $resolver->forget();

    expect($resolver->enabledForRequest($flag->key, featureRequest($candidate, $company)))
        ->toBeFalse();
});

it('keeps percentage rollout assignment stable across requests and resolver instances', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    FeatureFlag::query()->create([
        'key' => 'stable-rollout',
        'name' => 'Stable rollout',
        'enabled' => true,
        'rollout_percentage' => 37,
    ]);
    $request = featureRequest($candidate);
    $resolver = app(FeatureFlagResolver::class);
    $resolver->forget();
    $first = $resolver->enabledForRequest('stable-rollout', $request);

    expect($resolver->enabledForRequest('stable-rollout', featureRequest($candidate, null, 'other-session')))
        ->toBe($first);

    $resolver->forget();
    expect(app(FeatureFlagResolver::class)->enabledForRequest('stable-rollout', $request))
        ->toBe($first);
});
