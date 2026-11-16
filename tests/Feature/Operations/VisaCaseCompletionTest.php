<?php

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\EntitlementLedger;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\NotificationDelivery;
use App\Models\Plan;
use App\Models\User;
use App\Models\VisaCase;
use App\Models\VisaTask;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function completedVisaFixture(): array
{
    $plan = Plan::factory()->create(['visa_credits_per_term' => 1]);
    $owner = User::factory()->create([
        'role' => UserRole::Company,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'subscription_started_at' => now()->startOfDay(),
        'subscription_renews_at' => now()->addMonths(2),
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);
    $profile = CandidateProfile::factory()->create();
    $candidate = $profile->user;
    $candidate->forceFill([
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ])->save();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
        'status' => 'visa_in_progress',
    ]);
    $case = VisaCase::query()->create([
        'application_id' => $application->getKey(),
        'company_id' => $company->getKey(),
        'candidate_profile_id' => $profile->getKey(),
        'status' => 'active',
        'started_at' => now(),
    ]);
    foreach ([
        'registration', 'documents', 'review', 'employer', 'contract',
        'recognition', 'visa', 'flight', 'registration_de', 'work_start',
    ] as $index => $key) {
        $case->steps()->create([
            'key' => $key,
            'title' => str($key)->headline(),
            'status' => $index === 0 ? 'in_progress' : 'open',
            'visibility' => 'shared',
        ]);
    }
    $credit = app(EntitlementService::class)->consumeVisaCredit($company, $case->getKey());
    $case->update([
        'credit_status' => 'consumed',
        'credit_source' => $credit['source'],
        'credit_usage_period_id' => $credit['usage_period_id'],
        'credit_ledger_id' => $credit['ledger_id'],
    ]);
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
    ]);

    return compact(
        'admin', 'application', 'candidate', 'case', 'company', 'job', 'owner',
        'plan', 'profile',
    );
}

it('applies allowed audited case transitions idempotently with optimistic concurrency', function () {
    $data = completedVisaFixture();
    $case = $data['case'];
    $admin = $data['admin'];

    $payload = [
        'action' => 'block',
        'version' => 0,
        'reason' => 'Behördliche Rückfrage ist noch offen.',
    ];
    $this->actingAs($admin)->patch(route('admin.visa.update', $case), $payload)->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.visa.update', $case), $payload)->assertRedirect();

    expect($case->refresh()->status->value)->toBe('blocked')
        ->and($case->version)->toBe(1)
        ->and(AuditLog::query()->where('event', 'visa.case_block')->count())->toBe(1)
        ->and($case->events()->where('event', 'visa.case.block')->count())->toBe(1);

    $this->actingAs($admin)->patch(route('admin.visa.update', $case), [
        'action' => 'assign',
        'version' => 0,
        'assigned_to' => $admin->getKey(),
    ])->assertSessionHasErrors('version');

    $this->actingAs($admin)->patch(route('admin.visa.update', $case), [
        'action' => 'resume',
        'version' => 1,
    ])->assertRedirect();
    expect($case->refresh()->status->value)->toBe('active');
});

it('refunds and reserves the Visa credit exactly once across cancel and resume retries', function () {
    $data = completedVisaFixture();
    $case = $data['case'];
    $admin = $data['admin'];

    $payload = [
        'action' => 'not_required',
        'version' => 0,
        'reason' => 'Arbeitsgenehmigung wurde extern bestätigt.',
    ];
    $this->actingAs($admin)->patch(route('admin.visa.update', $case), $payload)->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.visa.update', $case), $payload)->assertRedirect();
    expect($case->refresh()->credit_status)->toBe('refunded')
        ->and(app(EntitlementService::class)->summary($data['company'])['visa_credits']['used'])->toBe(0);

    $this->actingAs($admin)->patch(route('admin.visa.update', $case), [
        'action' => 'resume',
        'version' => 1,
    ])->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.visa.update', $case), [
        'action' => 'resume',
        'version' => 1,
    ])->assertRedirect();
    expect($case->refresh()->credit_status)->toBe('consumed')
        ->and(app(EntitlementService::class)->summary($data['company'])['visa_credits']['used'])->toBe(1)
        ->and(EntitlementLedger::query()->where('source', 'visa_case_refund')->count())->toBe(0);
});

it('validates responsible users and separates company candidate support and admin views', function () {
    $data = completedVisaFixture();
    $foreign = User::factory()->create(['role' => UserRole::Company]);
    $step = $data['case']->steps()->firstOrFail();

    $this->actingAs($data['owner'])
        ->patch(route('employer.visa.steps', $step), [
            'status' => 'in_progress',
            'responsible_user_id' => $foreign->getKey(),
        ])
        ->assertStatus(422);

    $this->actingAs($data['owner'])
        ->patch(route('employer.visa.steps', $step), [
            'status' => 'in_progress',
            'responsible_user_id' => $data['owner']->getKey(),
        ])
        ->assertRedirect();

    $support = User::factory()->create([
        'role' => UserRole::Support,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
    ]);
    $this->actingAs($support)->get(route('admin.visa.index'))->assertOk();
    $this->actingAs($support)
        ->patch(route('admin.visa.update', $data['case']), [
            'action' => 'assign', 'version' => 1, 'assigned_to' => $support->getKey(),
        ])->assertForbidden();
    $this->actingAs($data['candidate'])
        ->get(route('candidate.applications'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('applications.0.visa_case.id', $data['case']->getKey())
            ->has('applications.0.visa_case.steps', 10));
});

it('creates tasks and document assignments without exporting private storage URLs', function () {
    $data = completedVisaFixture();
    $step = $data['case']->steps()->firstOrFail();
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => $data['profile']->getKey(),
        'type' => CandidateDocumentType::Passport,
        'title' => 'Pass',
        'disk' => 'private',
        'path' => 'candidate-documents/private-passport.pdf',
        'original_name' => 'private-passport.pdf',
        'mime_type' => 'application/pdf',
        'status' => CandidateDocumentStatus::Verified,
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
        'shared_with_employers' => true,
    ]);

    $this->actingAs($data['admin'])->post(route('admin.visa.tasks.store', [
        'case' => $data['case'], 'step' => $step,
    ]), [
        'title' => 'Übersetzung beauftragen',
        'assigned_to' => $data['owner']->getKey(),
        'due_at' => today()->addDay()->toDateString(),
        'visibility' => 'shared',
    ])->assertRedirect();
    $this->actingAs($data['admin'])->post(route('admin.visa.documents.store', $data['case']), [
        'candidate_document_id' => $document->getKey(),
        'visa_step_id' => $step->getKey(),
        'visibility' => 'shared',
        'translation_status' => 'pending',
        'review_status' => 'approved',
    ])->assertRedirect();

    $export = $this->actingAs($data['admin'])->get(route('admin.visa.export', $data['case']));
    $export->assertOk();
    expect($export->streamedContent())->toContain('Übersetzung beauftragen')
        ->not->toContain('candidate-documents/private-passport.pdf')
        ->not->toContain('private-passport.pdf')
        ->not->toContain('http://')
        ->not->toContain('https://');

    $this->actingAs($data['owner'])
        ->get(route('employer.visa'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cases.0.steps.0.tasks', 1)
            ->has('cases.0.documents', 1));
});

it('sends deadline and escalation notifications idempotently', function () {
    Notification::fake();
    $data = completedVisaFixture();
    $data['case']->update(['assigned_to' => $data['admin']->getKey()]);
    VisaTask::query()->create([
        'visa_step_id' => $data['case']->steps()->firstOrFail()->getKey(),
        'assigned_to' => $data['admin']->getKey(),
        'created_by' => $data['admin']->getKey(),
        'title' => 'Überfällige Prüfung',
        'status' => 'open',
        'visibility' => 'platform',
        'due_at' => today()->subDays(2),
    ]);

    expect(Artisan::call('erin:visa:send-deadline-notifications'))->toBe(0)
        ->and(Artisan::call('erin:visa:send-deadline-notifications'))->toBe(0)
        ->and(NotificationDelivery::query()->where('event', 'visa.deadline')->count())->toBe(1);
});
