<?php

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function retiredProductivityOwner(): array
{
    $owner = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'subscription_status' => 'active',
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return compact('owner', 'company');
}

it('keeps the retired productivity page unavailable', function () {
    ['owner' => $owner, 'company' => $company] = retiredProductivityOwner();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get('/employer/productivity')
        ->assertNotFound();
});

it('rejects new recruiter invitations while retaining historical enum compatibility', function () {
    ['owner' => $owner, 'company' => $company] = retiredProductivityOwner();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.team.invite'), [
            'email' => 'new-recruiter@example.test',
            'role' => 'recruiter',
        ])
        ->assertSessionHasErrors('role');

    $this->assertDatabaseMissing('company_invitations', [
        'company_id' => $company->getKey(),
        'email' => 'new-recruiter@example.test',
    ]);
});

it('rejects assigning the recruiter role to an existing member', function () {
    ['owner' => $owner, 'company' => $company] = retiredProductivityOwner();
    $member = User::factory()->create(['role' => UserRole::Company]);
    $membership = CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $member->getKey(),
        'role' => CompanyMemberRole::Viewer,
        'accepted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.team.members.update', $membership), [
            'role' => 'recruiter',
        ])
        ->assertSessionHasErrors('role');

    expect($membership->refresh()->role)->toBe(CompanyMemberRole::Viewer);
});
