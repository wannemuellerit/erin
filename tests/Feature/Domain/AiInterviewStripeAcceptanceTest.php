<?php

namespace Tests\Feature\Domain;

use App\Contracts\AiProvider;
use App\Contracts\VideoProvider;
use App\Enums\AiRunStatus;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use App\Listeners\SyncStripePurchase;
use App\Listeners\SyncStripeSubscription;
use App\Models\AiRun;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanyUsagePeriod;
use App\Models\EntitlementLedger;
use App\Models\IntegrationReceipt;
use App\Models\Interview;
use App\Models\InterviewProposal;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\ErinAcceptanceAiProvider;
use Tests\Support\ErinAcceptanceVideoProvider;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Company, 2: Plan}
 */
function erinIntegrationEmployer(array $companyAttributes = [], array $planAttributes = []): array
{
    $plan = Plan::factory()->create([
        'ai_credits_monthly' => 10,
        ...$planAttributes,
    ]);
    $owner = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
        ...$companyAttributes,
    ]);

    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return [$owner, $company, $plan];
}

/**
 * @return array{0: User, 1: Company, 2: User, 3: JobApplication}
 */
function erinIntegrationInterviewParticipants(): array
{
    [$owner, $company] = erinIntegrationEmployer();
    $profile = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
        'status' => ApplicationStatus::InReview,
    ]);

    return [$owner, $company, $profile->user, $application];
}

it('records a fake AI result, consumes one company credit, and never changes the application status', function () {
    [$owner, $company] = erinIntegrationEmployer();
    $profile = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
        'status' => ApplicationStatus::InReview,
    ]);
    $provider = new ErinAcceptanceAiProvider;
    $this->app->instance(AiProvider::class, $provider);

    $this->actingAs($owner)
        ->postJson(route('ai.run'), [
            'task' => 'applications_summarize',
            'input' => [
                'application_id' => $application->getKey(),
                'facts' => ['Berufserfahrung vorhanden', 'Deutsch B1'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('model', 'fake-recruiting-model')
        ->assertJsonPath('result.title', 'Neutrale Zusammenfassung')
        ->assertJsonPath('human_review_required', true);

    $run = AiRun::query()->sole();
    $usage = CompanyUsagePeriod::query()->sole();

    expect($provider->calls)->toBe(1)
        ->and($provider->requests[0]->task)->toBe('applications_summarize')
        ->and($run->company_id)->toBe($company->getKey())
        ->and($run->status)->toBe(AiRunStatus::Completed)
        ->and($run->model)->toBe('fake-recruiting-model')
        ->and($run->output)->toMatchArray([
            'title' => 'Neutrale Zusammenfassung',
            'content' => 'Die fachlichen Angaben wurden zusammengefasst.',
            'suggestions' => ['Menschlich prüfen'],
            'caveats' => ['Keine automatische Entscheidung'],
        ])
        ->and($run->input_tokens)->toBe(41)
        ->and($run->output_tokens)->toBe(17)
        ->and($usage->ai_credits_used)->toBe(1)
        ->and($application->refresh()->status)->toBe(ApplicationStatus::InReview);
});

it('blocks CV and profile AI tasks without an active purpose consent', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    CandidateProfile::factory()->create(['user_id' => $candidate->getKey()]);
    $provider = new ErinAcceptanceAiProvider;
    $this->app->instance(AiProvider::class, $provider);

    foreach (['cv_improve', 'profile_improve'] as $task) {
        $this->actingAs($candidate)
            ->postJson(route('ai.run'), [
                'task' => $task,
                'input' => ['content' => 'Vertraulicher Profilinhalt'],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);
    }

    expect($provider->calls)->toBe(0)
        ->and(AiRun::query()->count())->toBe(0);
});

it('supports proposal, counterproposal and confirmation with participant-only signed ICS access', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-03 09:00:00', 'Europe/Berlin'));
    [$owner, $company, $candidate, $application] = erinIntegrationInterviewParticipants();
    $originalStart = now()->addDays(2)->setTime(10, 0);
    $originalEnd = $originalStart->copy()->addHour();

    $this->actingAs($owner)
        ->post(route('interviews.propose', $application), [
            'slots' => [[
                'starts_at' => $originalStart->toIso8601String(),
                'ends_at' => $originalEnd->toIso8601String(),
                'timezone' => 'Europe/Berlin',
                'note' => 'Erster Vorschlag',
            ]],
        ])
        ->assertRedirect(route('interviews.index'));

    $interview = Interview::query()->sole();
    $originalProposal = InterviewProposal::query()->sole();

    expect($interview->status)->toBe(InterviewStatus::Proposed)
        ->and($interview->organizer_id)->toBe($owner->getKey())
        ->and($interview->proposed_by)->toBe($owner->getKey())
        ->and($interview->livekit_room_name)->toStartWith('erin-interview-')
        ->and($originalProposal->status)->toBe('pending');

    $counterStart = now()->addDays(3)->setTime(14, 0);
    $counterEnd = $counterStart->copy()->addMinutes(45);

    $this->actingAs($candidate)
        ->from(route('interviews.index'))
        ->post(route('interviews.respond', $interview), [
            'response' => 'counter',
            'slots' => [[
                'starts_at' => $counterStart->toIso8601String(),
                'ends_at' => $counterEnd->toIso8601String(),
                'timezone' => 'Europe/Berlin',
            ]],
            'note' => 'Alternative der Fachkraft',
        ])
        ->assertRedirect(route('interviews.index'));

    $counterProposal = $interview->proposals()->where('status', 'pending')->sole();

    expect($interview->refresh()->status)->toBe(InterviewStatus::CounterProposed)
        ->and($originalProposal->refresh()->status)->toBe('superseded')
        ->and($counterProposal->proposed_by)->toBe($candidate->getKey());

    $this->actingAs($owner)
        ->from(route('interviews.index'))
        ->post(route('interviews.respond', $interview), [
            'response' => 'accept',
            'proposal_id' => $counterProposal->getKey(),
        ])
        ->assertRedirect(route('interviews.index'));

    $interview->refresh();
    $application->refresh();

    expect($interview->status)->toBe(InterviewStatus::Confirmed)
        ->and($interview->starts_at?->equalTo($counterStart))->toBeTrue()
        ->and($interview->ends_at?->equalTo($counterEnd))->toBeTrue()
        ->and($counterProposal->refresh()->status)->toBe('accepted')
        ->and($application->status)->toBe(ApplicationStatus::InterviewScheduled)
        ->and($application->statusHistory()->where('to_status', ApplicationStatus::InterviewScheduled->value)->count())->toBe(1);

    $signedIcs = URL::temporarySignedRoute(
        'interviews.ics',
        now()->addWeek(),
        ['interview' => $interview],
    );

    $candidateIcs = $this->actingAs($candidate)->get($signedIcs);
    $candidateIcs
        ->assertOk()
        ->assertHeader('content-type', 'text/calendar; charset=utf-8')
        ->assertDownload('erin-interview-'.$interview->getKey().'.ics');

    expect($candidateIcs->getContent())->toContain(
        'BEGIN:VCALENDAR',
        'DTSTART:'.$counterStart->utc()->format('Ymd\\THis\\Z'),
        'SUMMARY:Erin Interview',
        'END:VCALENDAR',
    );

    $this->actingAs($owner)->get($signedIcs)->assertOk();
    $this->actingAs($candidate)
        ->get(route('interviews.ics', $interview))
        ->assertForbidden();

    $stranger = User::factory()->create(['role' => UserRole::Candidate]);
    $this->actingAs($stranger)->get($signedIcs)->assertForbidden();

    expect($company->getKey())->toBe($application->jobPosting->company_id);
});

it('issues video access only inside the interview window and only to participants', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-03 09:00:00', 'Europe/Berlin'));
    [$owner, , $candidate, $application] = erinIntegrationInterviewParticipants();
    $startsAt = now()->addDays(2)->setTime(11, 0);
    $endsAt = $startsAt->copy()->addHour();
    $interview = Interview::query()->create([
        'application_id' => $application->getKey(),
        'organizer_id' => $owner->getKey(),
        'proposed_by' => $owner->getKey(),
        'status' => InterviewStatus::Confirmed,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'timezone' => 'Europe/Berlin',
        'livekit_room_name' => 'erin-video-window-acceptance',
        'confirmed_at' => now(),
    ]);
    $video = new ErinAcceptanceVideoProvider;
    $this->app->instance(VideoProvider::class, $video);

    $this->actingAs($candidate)
        ->postJson(route('interviews.token', $interview))
        ->assertForbidden();

    $this->travelTo($startsAt->copy()->subMinutes(10));
    $stranger = User::factory()->create(['role' => UserRole::Candidate]);

    $this->actingAs($stranger)
        ->postJson(route('interviews.token', $interview))
        ->assertForbidden();

    $this->actingAs($candidate)
        ->postJson(route('interviews.token', $interview))
        ->assertOk()
        ->assertJsonPath('roomName', 'erin-video-window-acceptance')
        ->assertJsonPath('participantIdentity', 'erin-user-'.$candidate->getKey())
        ->assertJsonPath('token', 'signed-fake-video-token');

    $this->actingAs($owner)
        ->postJson(route('interviews.token', $interview))
        ->assertOk()
        ->assertJsonPath('participantIdentity', 'erin-user-'.$owner->getKey());

    expect($video->calls)->toHaveCount(2)
        ->and($video->calls[0]['metadata']['recording_allowed'])->toBeFalse()
        ->and($video->calls[0]['metadata']['interview_id'])->toBe($interview->getKey())
        ->and($video->calls[0]['metadata']['e2ee_key'])->toBeString()->not->toBeEmpty()
        ->and($video->calls[1]['metadata']['e2ee_key'])->toBe($video->calls[0]['metadata']['e2ee_key']);

    $this->travelTo($endsAt->copy()->addMinutes(16));

    $this->actingAs($candidate)
        ->postJson(route('interviews.token', $interview))
        ->assertForbidden();

    expect($video->calls)->toHaveCount(2);
});

it('handles duplicate Stripe subscription events once and preserves active and past due access', function () {
    $this->travelTo(Carbon::parse('2026-08-03 09:00:00', 'Europe/Berlin'));
    $plan = Plan::factory()->create(['stripe_price_id' => 'price_business_acceptance']);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_subscription_acceptance',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $listener = app(SyncStripeSubscription::class);
    $entitlements = app(EntitlementService::class);
    $periodStart = now()->startOfDay();
    $periodEnd = $periodStart->copy()->addMonths(4);

    $pastDuePayload = [
        'id' => 'evt_subscription_past_due_acceptance',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_acceptance',
            'customer' => $company->stripe_id,
            'status' => 'past_due',
            'cancel_at_period_end' => false,
            'current_period_start' => $periodStart->getTimestamp(),
            'current_period_end' => $periodEnd->getTimestamp(),
            'items' => ['data' => [[
                'price' => ['id' => $plan->stripe_price_id],
            ]]],
        ]],
    ];

    $listener->handle(new WebhookHandled($pastDuePayload));
    $receipt = IntegrationReceipt::query()->sole();
    $firstProcessedAt = $receipt->processed_at?->getTimestamp();
    $this->travelTo(now()->addMinutes(5));
    $listener->handle(new WebhookHandled($pastDuePayload));
    $company->refresh();

    expect(IntegrationReceipt::query()->count())->toBe(1)
        ->and($receipt->fresh()->processed_at?->getTimestamp())->toBe($firstProcessedAt)
        ->and($company->current_plan_id)->toBe($plan->getKey())
        ->and($company->status)->toBe(CompanyStatus::Active)
        ->and($company->subscription_status)->toBe('past_due')
        ->and($entitlements->hasPortalAccess($company))->toBeTrue();

    $activePayload = $pastDuePayload;
    $activePayload['id'] = 'evt_subscription_active_acceptance';
    $activePayload['data']['object']['status'] = 'active';

    $listener->handle(new WebhookHandled($activePayload));
    $listener->handle(new WebhookHandled($activePayload));
    $company->refresh();

    expect(IntegrationReceipt::query()->count())->toBe(2)
        ->and(IntegrationReceipt::query()
            ->where('event_id', 'evt_subscription_active_acceptance')
            ->where('status', 'processed')
            ->count())->toBe(1)
        ->and($company->subscription_status)->toBe('active')
        ->and($entitlements->hasPortalAccess($company))->toBeTrue();
});

it('identifies the base plan beside seat add-ons and never reactivates an administratively blocked company', function () {
    $plan = Plan::factory()->create(['stripe_price_id' => 'price_base_business']);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_blocked_subscription',
        'status' => CompanyStatus::Blocked,
        'subscription_status' => 'past_due',
    ]);
    $listener = app(SyncStripeSubscription::class);
    $payload = [
        'id' => 'evt_blocked_with_seat_addon',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_blocked_with_seat_addon',
            'customer' => $company->stripe_id,
            'status' => 'active',
            'current_period_start' => now()->getTimestamp(),
            'current_period_end' => now()->addMonths(4)->getTimestamp(),
            'items' => ['data' => [
                ['price' => ['id' => 'price_recruiter_seat'], 'quantity' => 3],
                ['price' => ['id' => $plan->stripe_price_id], 'quantity' => 1],
            ]],
        ]],
    ];

    $listener->handle(new WebhookHandled($payload));
    $company->refresh();

    expect($company->current_plan_id)->toBe($plan->getKey())
        ->and($company->subscription_status)->toBe('active')
        ->and($company->status)->toBe(CompanyStatus::Blocked)
        ->and(app(EntitlementService::class)->hasPortalAccess($company))->toBeFalse();
});

it('does not grant purchased visa credits twice for a duplicate Stripe checkout event', function () {
    $company = Company::factory()->create();
    $listener = app(SyncStripePurchase::class);
    $payload = [
        'id' => 'evt_visa_purchase_acceptance',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_visa_purchase_acceptance',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_visa_purchase_acceptance',
            'metadata' => [
                'purchase_type' => 'visa_credits',
                'company_id' => (string) $company->getKey(),
                'credits' => '5',
            ],
        ]],
    ];

    $listener->handle(new WebhookReceived($payload));
    $listener->handle(new WebhookReceived($payload));

    expect(IntegrationReceipt::query()
        ->where('provider', 'stripe:received')
        ->where('event_id', $payload['id'])
        ->where('status', 'processed')
        ->count())->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('company_id', $company->getKey())
            ->where('resource', 'visa')
            ->where('source', 'stripe_purchase')
            ->count())->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('company_id', $company->getKey())
            ->where('resource', 'visa')
            ->where('source', 'stripe_purchase')
            ->sum('amount'))->toEqual(5);
});
