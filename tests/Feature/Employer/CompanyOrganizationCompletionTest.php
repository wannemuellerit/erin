<?php

use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyLocation;
use App\Models\CompanyMembership;
use App\Models\CompanyTeam;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

it('preserves location identity and assignments across repeated company saves and rejects foreign ids', function () {
    [$owner, $company, $membership] = erinOrganizationCompany();
    $location = $company->locations()->create(['name' => 'Berlin', 'city' => 'Berlin', 'country_code' => 'DE']);
    $membership->update(['location_id' => $location->id]);
    $job = JobPosting::factory()->create(['company_id' => $company->id, 'location_id' => $location->id]);
    $payload = [
        'name' => $company->name, 'industry' => 'Logistik', 'country_code' => 'DE',
        'registered_country_code' => 'DE', 'default_target_country_code' => 'DE',
        'city' => 'Berlin', 'description' => 'Unser Unternehmen.',
        'locations' => [['id' => $location->id, 'name' => 'Zentrale', 'city' => 'Berlin', 'country_code' => 'DE']],
    ];
    foreach (range(1, 2) as $attempt) {
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->from(route('employer.company'))->put(route('employer.company.update'), $payload)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        expect($company->locations()->count())->toBe(1)
            ->and($location->refresh()->name)->toBe('Zentrale')
            ->and($membership->refresh()->location_id)->toBe($location->id)
            ->and($job->refresh()->location_id)->toBe($location->id);
    }
    $foreign = Company::factory()->create()->locations()->create(['name' => 'Fremd', 'city' => 'Paris', 'country_code' => 'FR']);
    $payload['locations'][0]['id'] = $foreign->id;
    $this->put(route('employer.company.update'), $payload)->assertSessionHasErrors('locations.0.id');
    expect($foreign->refresh()->name)->toBe('Fremd')->and($location->fresh())->not->toBeNull();
    unset($payload['locations']);
    $this->put(route('employer.company.update'), $payload)->assertSessionHasNoErrors();
    expect($location->fresh())->not->toBeNull();
    // FormData omits the last removed array item entirely.
    $payload['locations_submitted'] = true;
    $this->post(route('employer.company.update').'?_method=PUT', $payload)->assertSessionHasNoErrors();
    expect($location->fresh())->toBeNull()->and($job->refresh()->location_id)->toBeNull();
});

it('reports SMTP failure without claiming an invitation was sent and permits resend', function () {
    [$owner, $company] = erinOrganizationCompany();
    Mail::shouldReceive('raw')->once()->andThrow(new TransportException('SMTP unavailable'));
    $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
        ->post(route('employer.team.invite'), ['email' => 'tester@example.test', 'role' => 'viewer'])
        ->assertSessionHasErrors('email')->assertSessionMissing('success');
    $invitation = $company->invitations()->sole();
    Mail::shouldReceive('raw')->once()->andReturnNull();
    $this->post(route('employer.team.invitations.resend', $invitation))
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($company->invitations()->count())->toBe(1);
});

/** @return array{0: User, 1: Company, 2: CompanyMembership} */
function erinOrganizationCompany(?int $seatLimit = 5, string $slug = 'business'): array
{
    $plan = Plan::factory()->create([
        'slug' => $slug.'-'.str()->random(6),
        'seat_limit' => $seatLimit,
    ]);
    $owner = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
    ]);
    $membership = CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return [$owner, $company, $membership];
}

dataset('organization seat plans', [
    'basic' => [1, 'basic'],
    'business' => [5, 'business'],
    'premium' => [15, 'premium'],
    'enterprise' => [null, 'enterprise'],
]);

it('enforces every plan seat limit while enterprise remains unlimited', function (?int $limit, string $slug) {
    [, $company] = erinOrganizationCompany($limit, $slug);
    $membersToCreate = $limit === null ? 25 : max(0, $limit - 1);
    foreach (range(1, $membersToCreate) as $index) {
        if ($membersToCreate === 0) {
            break;
        }
        $user = User::factory()->create(['role' => UserRole::Company]);
        CompanyMembership::query()->create([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
            'role' => CompanyMemberRole::Recruiter,
            'accepted_at' => now(),
        ]);
    }

    if ($limit === null) {
        expect(fn () => app(EntitlementService::class)->assertCanAddSeat($company))->not->toThrow(DomainException::class);
    } else {
        expect(fn () => app(EntitlementService::class)->assertCanAddSeat($company))->toThrow(DomainException::class);
    }
})->with('organization seat plans');

it('atomically reserves seats and treats duplicate expired accepted and revoked invitations idempotently', function () {
    Mail::fake();
    [$owner, $company] = erinOrganizationCompany(5);
    $session = ['active_company_id' => $company->getKey()];

    foreach (range(1, 4) as $index) {
        $this->actingAs($owner)->withSession($session)->post(route('employer.team.invite'), [
            'email' => "pending{$index}@example.test",
            'role' => 'viewer',
        ])->assertRedirect();
    }
    $first = CompanyInvitation::query()->where('email', 'pending1@example.test')->firstOrFail();
    $firstToken = $first->token;
    $this->actingAs($owner)->withSession($session)->post(route('employer.team.invite'), [
        'email' => 'pending1@example.test',
        'role' => 'viewer',
    ])->assertRedirect();
    expect(CompanyInvitation::query()->count())->toBe(4)
        ->and($first->refresh()->token)->toBe($firstToken);

    $this->actingAs($owner)->withSession($session)->post(route('employer.team.invite'), [
        'email' => 'over-limit@example.test',
        'role' => 'viewer',
    ])->assertSessionHasErrors('email');

    $first->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($owner)->withSession($session)
        ->post(route('employer.team.invitations.resend', $first))
        ->assertRedirect();
    expect($first->refresh()->token)->not->toBe($firstToken)
        ->and($first->expires_at->isFuture())->toBeTrue();

    $this->actingAs($owner)->withSession($session)
        ->delete(route('employer.team.invitations.revoke', $first))
        ->assertRedirect();
    $revokedAt = $first->refresh()->revoked_at;
    $this->actingAs($owner)->withSession($session)
        ->delete(route('employer.team.invitations.revoke', $first))
        ->assertRedirect();
    expect($first->refresh()->revoked_at?->equalTo($revokedAt))->toBeTrue();

    $invitee = User::factory()->create([
        'email' => 'accepted@example.test',
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $this->actingAs($owner)->withSession($session)->post(route('employer.team.invite'), [
        'email' => $invitee->email,
        'role' => 'viewer',
    ])->assertRedirect();
    $accepted = CompanyInvitation::query()->where('email', $invitee->email)->sole();
    $this->actingAs($invitee)->get(route('company-invitations.accept', $accepted->token))->assertRedirect(route('dashboard'));
    $this->actingAs($invitee)->get(route('company-invitations.accept', $accepted->token))->assertRedirect(route('dashboard'));
    expect($company->memberships()->where('user_id', $invitee->getKey())->count())->toBe(1);
});

it('provides tenant safe audited role team location contact job and application assignments', function () {
    [$owner, $company] = erinOrganizationCompany(5);
    [$foreignOwner, $foreignCompany] = erinOrganizationCompany(5, 'foreign');
    $session = ['active_company_id' => $company->getKey()];
    $memberUser = User::factory()->create(['role' => UserRole::Company]);
    $membership = CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $memberUser->getKey(),
        'role' => CompanyMemberRole::Recruiter,
        'accepted_at' => now(),
    ]);
    $location = CompanyLocation::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country_code' => 'DE',
    ]);
    $foreignTeam = CompanyTeam::query()->create(['company_id' => $foreignCompany->getKey(), 'name' => 'Foreign']);

    $this->actingAs($owner)->withSession($session)->post(route('employer.team.teams.store'), [
        'name' => 'Talent Acquisition',
        'location_id' => $location->getKey(),
        'contact_membership_id' => $membership->getKey(),
        'membership_ids' => [$membership->getKey()],
    ])->assertRedirect();
    $team = CompanyTeam::query()->where('company_id', $company->getKey())->sole();

    $this->actingAs($owner)->withSession($session)->patch(route('employer.team.members.update', $membership), [
        'role' => 'viewer',
        'location_id' => $location->getKey(),
        'team_ids' => [$team->getKey()],
    ])->assertRedirect();
    expect($membership->refresh()->role)->toBe(CompanyMemberRole::Viewer)
        ->and($membership->location_id)->toBe($location->getKey())
        ->and($membership->teams()->whereKey($team->getKey())->exists())->toBeTrue();

    $this->actingAs($memberUser)->withSession($session)
        ->get(route('employer.team'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.capabilities', fn ($capabilities): bool => ! $capabilities->contains('team.manage')));

    $job = JobPosting::factory()->create(['company_id' => $company->getKey(), 'created_by' => $owner->getKey()]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => CandidateProfile::factory()->create()->getKey(),
    ]);
    $assignment = ['company_team_id' => $team->getKey(), 'contact_membership_id' => $membership->getKey()];
    $this->actingAs($owner)->withSession($session)
        ->patch(route('employer.team.jobs.organization', $job), $assignment)->assertRedirect();
    $this->actingAs($owner)->withSession($session)
        ->patch(route('employer.team.applications.organization', $application), $assignment)->assertRedirect();
    expect($job->refresh()->company_team_id)->toBe($team->getKey())
        ->and($application->refresh()->contact_membership_id)->toBe($membership->getKey());

    $this->actingAs($owner)->withSession($session)
        ->put(route('employer.team.teams.update', $foreignTeam), [
            'name' => 'Intrusion', 'membership_ids' => [],
        ])->assertNotFound();
    $this->actingAs($foreignOwner)->withSession(['active_company_id' => $foreignCompany->getKey()])
        ->patch(route('employer.team.members.update', $membership), ['role' => 'admin'])
        ->assertNotFound();

    $events = AuditLog::query()->where('company_id', $company->getKey())->pluck('event');
    expect($events)->toContain(
        'company.team_created',
        'company.member_updated',
        'company.job_organization_assigned',
        'company.application_organization_assigned',
    )->and(AuditLog::query()->where('company_id', $company->getKey())
        ->whereNotNull('before_values')->whereNotNull('after_values')->count())->toBeGreaterThanOrEqual(3);
});

it('requires recent reauthentication for the single-owner transfer', function () {
    [$owner, $company] = erinOrganizationCompany(5);
    $target = User::factory()->create(['role' => UserRole::Company]);
    $targetMembership = CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $target->getKey(),
        'role' => CompanyMemberRole::Admin,
        'accepted_at' => now(),
    ]);
    $session = ['active_company_id' => $company->getKey()];

    $this->actingAs($owner)->withSession($session)
        ->post(route('employer.team.transfer-ownership', $targetMembership))
        ->assertRedirect(route('password.confirm'));
    expect($targetMembership->refresh()->role)->toBe(CompanyMemberRole::Admin);

    $this->actingAs($owner)->withSession([
        ...$session,
        'auth.password_confirmed_at' => time(),
    ])->post(route('employer.team.transfer-ownership', $targetMembership))->assertRedirect();
    expect($targetMembership->refresh()->role)->toBe(CompanyMemberRole::Owner)
        ->and($company->memberships()->where('role', CompanyMemberRole::Owner)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'company.ownership_transferred')->exists())->toBeTrue();
});
