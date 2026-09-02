<?php

use App\Enums\CompanyMemberRole;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DomainCatalogSeeder::class));

/** @return array{0: User, 1: Company} */
function fadenEmployer(): array
{
    $user = User::factory()->create(['role' => UserRole::Company, 'onboarding_completed_at' => now()]);
    $company = Company::factory()->create(['subscription_status' => 'active']);
    CompanyMembership::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => CompanyMemberRole::Owner, 'accepted_at' => now()]);

    return [$user, $company];
}

/** @return array<string, mixed> */
function fadenJobPayload(Company $company): array
{
    $location = $company->locations()->create(['name' => 'Hauptsitz', 'city' => 'Köln', 'country_code' => 'DE']);

    return [
        'title' => 'Elektriker für moderne Industrieanlagen',
        'position' => 'Industrieelektriker',
        'summary' => str_repeat('Attraktive Tätigkeit in einem verlässlichen Team. ', 2),
        'description' => str_repeat('Sie arbeiten an modernen Anlagen und werden umfassend eingearbeitet. ', 3),
        'responsibilities' => str_repeat('Wartung, Installation und dokumentierte Fehlersuche. ', 2),
        'requirements' => str_repeat('Abgeschlossene Ausbildung und sorgfältige Arbeitsweise. ', 2),
        'benefits' => 'Unterkunft, Deutschkurs und strukturierte Einarbeitung.',
        'occupation_id' => Occupation::query()->firstOrFail()->id,
        'location_id' => $location->id,
        'expected_experience_years' => 2,
        'language_notes' => 'Deutsch für die tägliche Abstimmung.',
        'application_deadline' => now()->addMonth()->toDateString(),
        'start_date' => now()->addMonths(2)->toDateString(),
        'vacancies' => 3,
        'contact_name' => 'Erika Recruiting',
        'contact_email' => 'recruiting@example.test',
        'hours_min' => 35,
        'hours_max' => 40,
        'employment_type' => 'full_time',
        'compensation_min_cents' => 3600000,
        'compensation_max_cents' => 4500000,
        'currency' => 'EUR',
        'compensation_interval' => 'year',
        'salary_visible' => true,
        'is_remote' => false,
        'visa_package_available' => true,
        'skills' => [['id' => Skill::query()->firstOrFail()->id, 'importance' => 5, 'minimum_experience_years' => 1]],
        'languages' => [['id' => Language::query()->firstOrFail()->id, 'minimum_level' => 'B1', 'is_required' => true]],
        'screening_questions' => [[
            'question' => 'Können Sie im Schichtbetrieb arbeiten?', 'type' => 'choice', 'is_required' => true,
            'options' => ['Ja, flexibel', 'Nur Frühschicht'],
        ]],
    ];
}

it('creates a detailed draft with structured qualifications and screening questions', function () {
    [$user, $company] = fadenEmployer();
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employer.jobs.store'), fadenJobPayload($company))
        ->assertRedirect()->assertSessionHasNoErrors();

    $job = $company->jobPostings()->firstOrFail();
    expect($job->status)->toBe(JobStatus::Draft)
        ->and($job->summary)->not->toBeEmpty()
        ->and($job->vacancies)->toBe(3)
        ->and($job->skills()->count())->toBe(1)
        ->and($job->languages()->count())->toBe(1)
        ->and($job->screeningQuestions()->firstOrFail()->options)->toBe(['Ja, flexibel', 'Nur Frühschicht']);
});

it('rejects cross-tenant locations invalid ranges missing qualifications and malformed choice questions', function (array $changes, string $error) {
    [$user, $company] = fadenEmployer();
    [, $foreignCompany] = fadenEmployer();
    $foreignLocation = $foreignCompany->locations()->create(['name' => 'Fremd', 'city' => 'Berlin', 'country_code' => 'DE']);
    $payload = fadenJobPayload($company);
    if (($changes['location_id'] ?? null) === 'foreign') {
        $changes['location_id'] = $foreignLocation->id;
    }

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employer.jobs.store'), [...$payload, ...$changes])
        ->assertSessionHasErrors($error);
})->with([
    'cross tenant location' => [['location_id' => 'foreign'], 'location_id'],
    'invalid hours' => [['hours_min' => 45, 'hours_max' => 20], 'hours_max'],
    'missing skills' => [['skills' => []], 'skills'],
    'missing languages' => [['languages' => []], 'languages'],
    'choice without alternatives' => [[
        'screening_questions' => [[
            'question' => 'Auswahl?',
            'type' => 'choice',
            'is_required' => true,
            'options' => ['Nur eine'],
        ]],
    ], 'screening_questions.0.options'],
]);

it('duplicates all matching rules as an isolated draft but never copies media or applications', function () {
    [$user, $company] = fadenEmployer();
    $payload = fadenJobPayload($company);
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->post(route('employer.jobs.store'), $payload);
    $job = $company->jobPostings()->firstOrFail();

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post(route('employer.jobs.duplicate', $job))->assertRedirect();
    $copy = $company->jobPostings()->whereKeyNot($job->id)->firstOrFail();
    expect($copy->status)->toBe(JobStatus::Draft)
        ->and($copy->skills()->pluck('skills.id')->all())->toBe($job->skills()->pluck('skills.id')->all())
        ->and($copy->screeningQuestions()->count())->toBe(1)
        ->and($copy->media()->count())->toBe(0)
        ->and($copy->applications()->count())->toBe(0);
});

it('only deletes empty drafts or archives and preserves jobs with recruiting history', function () {
    [$user, $company] = fadenEmployer();
    $draft = JobPosting::factory()->create(['company_id' => $company->id, 'created_by' => $user->id, 'status' => JobStatus::Draft, 'published_at' => null]);
    $withHistory = JobPosting::factory()->create(['company_id' => $company->id, 'created_by' => $user->id, 'status' => JobStatus::Draft, 'published_at' => null]);
    JobApplication::factory()->create(['job_posting_id' => $withHistory->id]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->delete(route('employer.jobs.destroy', $withHistory))->assertStatus(422);
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->delete(route('employer.jobs.destroy', $draft))->assertRedirect(route('employer.jobs.index'));
    expect($withHistory->fresh())->not->toBeNull()->and(JobPosting::query()->find($draft->id))->toBeNull();
});

it('keeps screening questions and submitted answers immutable after the first application', function () {
    [$user, $company] = fadenEmployer();
    $payload = fadenJobPayload($company);
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->post(route('employer.jobs.store'), $payload);
    $job = $company->jobPostings()->firstOrFail();
    $question = $job->screeningQuestions()->firstOrFail();
    $application = JobApplication::factory()->create(['job_posting_id' => $job->id]);
    $application->screeningAnswers()->create(['job_screening_question_id' => $question->id, 'answer' => 'Ja, flexibel']);
    $changed = $payload;
    $changed['screening_questions'][0]['question'] = 'Nachträglich verändert?';

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->put(route('employer.jobs.update', $job), $changed)
        ->assertSessionHasErrors('screening_questions');

    expect($question->fresh()?->question)->toBe('Können Sie im Schichtbetrieb arbeiten?')
        ->and($application->screeningAnswers()->firstOrFail()->answer)->toBe('Ja, flexibel');

    $unchanged = $payload;
    $unchanged['title'] = 'Aktualisierte Stelle mit unverändertem Screening';
    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->put(route('employer.jobs.update', $job), $unchanged)
        ->assertSessionHasNoErrors();

    expect($job->fresh()?->title)->toBe('Aktualisierte Stelle mit unverändertem Screening')
        ->and($question->fresh()?->getKey())->toBe($question->getKey())
        ->and($application->screeningAnswers()->firstOrFail()->answer)->toBe('Ja, flexibel');
});

it('rejects unknown and type-invalid screening answers before creating an application', function (array $answers) {
    $profile = CandidateProfile::factory()->create(['completeness' => 100, 'published_at' => now()]);
    $profile->user->update(['onboarding_completed_at' => now()]);
    $job = JobPosting::factory()->create(['status' => JobStatus::Published, 'published_at' => now()]);
    $yesNo = $job->screeningQuestions()->create(['question' => 'Bereit?', 'type' => 'yes_no', 'is_required' => true, 'sort_order' => 0]);
    $choice = $job->screeningQuestions()->create(['question' => 'Schicht?', 'type' => 'choice', 'is_required' => true, 'options' => ['Früh', 'Spät'], 'sort_order' => 1]);
    $resolved = array_map(fn (array $answer) => ['question_id' => match ($answer['question_id']) {
        'yes_no' => $yesNo->id, 'choice' => $choice->id, default => 999999
    }, 'answer' => $answer['answer']], $answers);

    $this->actingAs($profile->user)->post(route('candidate.jobs.apply', $job), ['answers' => $resolved])
        ->assertSessionHasErrors('answers');
    expect($profile->applications()->count())->toBe(0);
})->with([
    'unknown question' => [[['question_id' => 'unknown', 'answer' => 'x'], ['question_id' => 'yes_no', 'answer' => 'yes'], ['question_id' => 'choice', 'answer' => 'Früh']]],
    'invalid yes no' => [[['question_id' => 'yes_no', 'answer' => 'maybe'], ['question_id' => 'choice', 'answer' => 'Früh']]],
    'invalid choice' => [[['question_id' => 'yes_no', 'answer' => 'yes'], ['question_id' => 'choice', 'answer' => 'Nacht']]],
]);
