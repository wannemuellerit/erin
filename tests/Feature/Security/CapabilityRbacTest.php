<?php

use App\Enums\Capability;
use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Plan;
use App\Models\PlatformRole;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Authorization\CapabilityResolver;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DomainCatalogSeeder::class);
});

/**
 * @return array{0: User, 1: Company, 2: CompanyMembership}
 */
function erinCapabilityMember(CompanyMemberRole $role): array
{
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
        'current_plan_id' => Plan::query()->value('id'),
    ]);
    $membership = CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return [$user, $company, $membership];
}

it('resolves the documented company role matrix from one source', function () {
    $resolver = app(CapabilityResolver::class);
    [, , $owner] = erinCapabilityMember(CompanyMemberRole::Owner);
    [, , $admin] = erinCapabilityMember(CompanyMemberRole::Admin);
    [, , $recruiter] = erinCapabilityMember(CompanyMemberRole::Recruiter);
    [, , $viewer] = erinCapabilityMember(CompanyMemberRole::Viewer);

    expect($resolver->for($owner->user, $owner))
        ->toContain(Capability::BillingManage->value, Capability::OwnershipTransfer->value)
        ->and($resolver->for($admin->user, $admin))
        ->toContain(Capability::TeamManage->value)
        ->not->toContain(Capability::BillingManage->value, Capability::OwnershipTransfer->value)
        ->and($resolver->for($recruiter->user, $recruiter))
        ->toContain(Capability::JobsManage->value, Capability::ApplicationsManage->value)
        ->not->toContain(Capability::CompanyManage->value, Capability::TeamManage->value)
        ->and($resolver->for($viewer->user, $viewer))
        ->toContain(Capability::JobsView->value, Capability::CandidateMarketplaceView->value)
        ->not->toContain(Capability::JobsManage->value, Capability::CandidateMarketplaceManage->value);
});

it('blocks viewer mutations and recruiter billing through direct HTTP requests', function () {
    [$viewer, $viewerCompany] = erinCapabilityMember(CompanyMemberRole::Viewer);
    [$recruiter, $recruiterCompany] = erinCapabilityMember(CompanyMemberRole::Recruiter);
    $plan = Plan::query()->firstOrFail();

    $this->actingAs($viewer)
        ->withSession(['active_company_id' => $viewerCompany->getKey()])
        ->post(route('employer.talent-lists.store'), ['name' => 'Nicht erlaubt'])
        ->assertForbidden();

    $this->actingAs($recruiter)
        ->withSession(['active_company_id' => $recruiterCompany->getKey()])
        ->post(route('employer.billing.checkout', $plan))
        ->assertForbidden();
});

it('keeps support and superadmin capabilities distinct', function () {
    $resolver = app(CapabilityResolver::class);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    expect($resolver->for($support))
        ->toContain(Capability::PlatformView->value, Capability::PlatformSupportManage->value)
        ->not->toContain(Capability::PlatformManage->value, Capability::BillingManage->value)
        ->and($resolver->for($admin))
        ->toContain(Capability::PlatformManage->value, Capability::BillingManage->value);
});

it('shares only the capabilities of the active accepted company membership', function () {
    [$user, $company] = erinCapabilityMember(CompanyMemberRole::Viewer);
    [, $foreignCompany] = erinCapabilityMember(CompanyMemberRole::Owner);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.billing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.active_company_id', $company->getKey())
            ->where('auth.capabilities', fn ($capabilities): bool => (
                $capabilities->contains(Capability::BillingView->value)
                && ! $capabilities->contains(Capability::BillingManage->value)
            )));

    $this->actingAs($user)
        ->withSession(['active_company_id' => $foreignCompany->getKey()])
        ->get(route('employer.billing'))
        ->assertForbidden();
});

it('transfers company ownership atomically and prevents admins from doing it', function () {
    [$owner, $company, $ownerMembership] = erinCapabilityMember(CompanyMemberRole::Owner);
    $target = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $targetMembership = CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $target->getKey(),
        'role' => CompanyMemberRole::Admin,
        'accepted_at' => now(),
    ]);

    $this->actingAs($target)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.team.transfer-ownership', $ownerMembership))
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.team.transfer-ownership', $targetMembership))
        ->assertRedirect();

    expect($ownerMembership->refresh()->role)->toBe(CompanyMemberRole::Admin)
        ->and($targetMembership->refresh()->role)->toBe(CompanyMemberRole::Owner)
        ->and($company->memberships()->where('role', CompanyMemberRole::Owner)->count())->toBe(1);
});

it('lets superadmins configure and assign restricted support roles', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $support = User::factory()->create(['role' => UserRole::Support]);

    $this->actingAs($admin)
        ->post(route('admin.settings.platform-roles.store'), [
            'name' => 'Nur Plattform lesen',
            'capabilities' => [Capability::PlatformView->value],
            'is_active' => true,
        ])
        ->assertRedirect();

    $role = PlatformRole::query()->sole();

    $this->actingAs($admin)
        ->patch(route('admin.users.platform-role.update', $support), [
            'platform_role_id' => $role->getKey(),
        ])
        ->assertRedirect();

    expect(app(CapabilityResolver::class)->for($support->refresh()))
        ->toContain(Capability::DashboardView->value, Capability::PlatformView->value)
        ->not->toContain(Capability::PlatformSupportManage->value);

    $this->actingAs($admin)
        ->delete(route('admin.settings.platform-roles.destroy', $role))
        ->assertSessionHasErrors('platform_role');

    $this->actingAs($admin)
        ->patch(route('admin.users.platform-role.update', $support), [
            'platform_role_id' => null,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->delete(route('admin.settings.platform-roles.destroy', $role))
        ->assertRedirect();

    expect(PlatformRole::query()->count())->toBe(0);
});

it('blocks restricted support staff from direct support mutations', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $requester = User::factory()->create(['role' => UserRole::Candidate]);
    $role = PlatformRole::query()->create([
        'name' => 'Nur Plattform lesen',
        'capabilities' => [Capability::PlatformView->value],
        'is_active' => true,
    ]);
    $support->update(['platform_role_id' => $role->getKey()]);
    $ticket = SupportTicket::query()->create([
        'requester_id' => $requester->getKey(),
        'number' => 'ERIN-RBAC-1',
        'subject' => 'Berechtigungsprüfung',
        'status' => 'open',
    ]);

    $this->actingAs($support)
        ->patch(route('admin.support.update', $ticket), [
            'status' => 'in_progress',
        ])
        ->assertForbidden();
});

it('rejects unsupported capabilities and non-support role assignments', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $companyUser = User::factory()->create(['role' => UserRole::Company]);

    $this->actingAs($admin)
        ->post(route('admin.settings.platform-roles.store'), [
            'name' => 'Unzulässige Rolle',
            'capabilities' => [Capability::PlatformManage->value],
            'is_active' => true,
        ])
        ->assertSessionHasErrors('capabilities.0');

    $role = PlatformRole::query()->create([
        'name' => 'Support lesen',
        'capabilities' => [Capability::PlatformView->value],
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.users.platform-role.update', $companyUser), [
            'platform_role_id' => $role->getKey(),
        ])
        ->assertUnprocessable();
});
