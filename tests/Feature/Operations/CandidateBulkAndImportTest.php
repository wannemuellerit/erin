<?php

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Jobs\ProcessCandidateImport;
use App\Models\ActivityEntry;
use App\Models\CandidateImport;
use App\Models\CandidateImportRow;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\ClamAvScanner;
use App\Services\Imports\CandidateImportReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function erinBulkEmployer(CompanyMemberRole $role = CompanyMemberRole::Owner): array
{
    $user = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();

    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return compact('user', 'company');
}

/**
 * @param  array<string, string|null>  $mapping
 */
function erinCandidateImport(
    Company $company,
    User $creator,
    string $contents,
    array $mapping,
    string $filename = 'kandidaten.csv',
): CandidateImport {
    $path = "candidate-imports/{$company->getKey()}/".uniqid('test-', true).'.csv';
    Storage::disk('private')->put($path, $contents);

    return CandidateImport::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $creator->getKey(),
        'original_filename' => $filename,
        'disk' => 'private',
        'storage_path' => $path,
        'status' => 'queued',
        'mapping' => $mapping,
    ]);
}

it('bulk-invites at most one hundred published candidates without leaking their identity', function () {
    Notification::fake();
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinBulkEmployer();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'title' => 'Elektriker/in',
    ]);
    $foreignJob = JobPosting::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignOwner->getKey(),
    ]);
    $published = CandidateProfile::factory()->create([
        'first_name' => 'Marta',
        'last_name' => 'Nowak',
        'desired_position' => 'Elektrikerin',
        'current_country_code' => 'PL',
    ]);
    $hidden = CandidateProfile::factory()->create(['published_at' => null]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidates.bulk.invite'), [
            'candidate_ids' => range(1, 101),
            'job_posting_id' => $job->getKey(),
        ])
        ->assertSessionHasErrors('candidate_ids');

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidates.bulk.invite'), [
            'candidate_ids' => [$published->getKey(), $hidden->getKey()],
            'job_posting_id' => $job->getKey(),
        ])
        ->assertUnprocessable();

    expect(JobInvitation::query()->count())->toBe(0);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidates.bulk.invite'), [
            'candidate_ids' => [$published->getKey()],
            'job_posting_id' => $foreignJob->getKey(),
        ])
        ->assertNotFound();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidates.bulk.invite'), [
            'candidate_ids' => [$published->getKey()],
            'job_posting_id' => $job->getKey(),
            'message' => 'Wir möchten dich kennenlernen.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invitation = JobInvitation::query()->sole();
    $activity = ActivityEntry::query()->where('event', 'candidate.invited')->sole();

    expect($invitation)
        ->job_posting_id->toBe($job->getKey())
        ->candidate_profile_id->toBe($published->getKey())
        ->status->toBe('pending')
        ->and($activity->company_id)->toBe($company->getKey())
        ->and($activity->payload)
        ->toMatchArray([
            'candidate_label' => 'Elektrikerin · PL',
            'job_title' => 'Elektriker/in',
        ])
        ->and(json_encode($activity->payload, JSON_THROW_ON_ERROR))
        ->not->toContain('Marta', 'Nowak');

    Notification::assertSentTo($published->user, ActivityNotification::class);
});

it('sends bulk messages only through conversations of the active tenant and current participant', function () {
    Notification::fake();
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinBulkEmployer();
    $candidate = CandidateProfile::factory()->create();
    $foreignCandidate = CandidateProfile::factory()->create();
    $unsharedCandidate = CandidateProfile::factory()->create();

    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $foreignJob = JobPosting::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignOwner->getKey(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $candidate->getKey(),
    ]);
    $foreignApplication = JobApplication::factory()->create([
        'job_posting_id' => $foreignJob->getKey(),
        'candidate_profile_id' => $foreignCandidate->getKey(),
    ]);
    $unsharedApplication = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $unsharedCandidate->getKey(),
    ]);

    $conversation = Conversation::query()->create([
        'company_id' => $company->getKey(),
        'application_id' => $application->getKey(),
        'type' => 'application',
    ]);
    $conversation->participants()->attach([
        $owner->getKey(),
        $candidate->user_id,
    ]);
    $foreignConversation = Conversation::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'application_id' => $foreignApplication->getKey(),
        'type' => 'application',
    ]);
    $foreignConversation->participants()->attach([
        $foreignOwner->getKey(),
        $foreignCandidate->user_id,
    ]);
    $unsharedConversation = Conversation::query()->create([
        'company_id' => $company->getKey(),
        'application_id' => $unsharedApplication->getKey(),
        'type' => 'application',
    ]);
    $unsharedConversation->participants()->attach([
        $candidate->user_id,
        $unsharedCandidate->user_id,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidates.bulk.message'), [
            'candidate_ids' => [
                $candidate->getKey(),
                $foreignCandidate->getKey(),
                $unsharedCandidate->getKey(),
            ],
            'message' => 'Bitte sende uns deine aktuellen Unterlagen.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $message = Message::query()->sole();

    expect($message)
        ->conversation_id->toBe($conversation->getKey())
        ->sender_id->toBe($owner->getKey())
        ->body->toBe('Bitte sende uns deine aktuellen Unterlagen.')
        ->and($message->metadata)->toBe(['bulk' => true])
        ->and($foreignConversation->messages()->count())->toBe(0)
        ->and($unsharedConversation->messages()->count())->toBe(0)
        ->and(ActivityEntry::query()->where('event', 'candidate.bulk_message_sent')->count())
        ->toBe(1);

    Notification::assertSentTo($candidate->user, ActivityNotification::class);
    Notification::assertNotSentTo($foreignCandidate->user, ActivityNotification::class);
});

it('previews CSV and XLSX uploads and validates unique column mappings per tenant', function () {
    Storage::fake('private');
    Queue::fake();
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    ['company' => $foreignCompany] = erinBulkEmployer();
    $this->mock(ClamAvScanner::class)
        ->shouldReceive('scan')
        ->twice()
        ->andReturn('clean');

    $csv = UploadedFile::fake()->createWithContent(
        'kandidaten.csv',
        "E-Mail;Aktuelle Position;Vorname\nmarta@example.com;Elektrikerin;Marta\n",
    );
    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidate-imports.store'), ['file' => $csv])
        ->assertRedirect()
        ->assertSessionHas('success');

    $xlsxPath = tempnam(sys_get_temp_dir(), 'erin-xlsx-');
    expect($xlsxPath)->not->toBeFalse();
    $writer = new Writer;
    $writer->openToFile($xlsxPath);
    $writer->addRow(Row::fromValues(['E-Mail', 'Aktuelle Position', 'Vorname']));
    $writer->addRow(Row::fromValues(['joao@example.com', 'LKW-Fahrer', 'João']));
    $writer->close();
    $xlsx = new UploadedFile(
        $xlsxPath,
        'kandidaten.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    try {
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->getKey()])
            ->post(route('employer.candidate-imports.store'), ['file' => $xlsx])
            ->assertRedirect()
            ->assertSessionHas('success');
    } finally {
        @unlink($xlsxPath);
    }

    expect(CandidateImport::query()->count())->toBe(2);
    $csvImport = CandidateImport::query()
        ->where('original_filename', 'kandidaten.csv')
        ->sole();
    $xlsxImport = CandidateImport::query()
        ->where('original_filename', 'kandidaten.xlsx')
        ->sole();

    expect($csvImport->mapping['headers'])
        ->toBe(['E-Mail', 'Aktuelle Position', 'Vorname'])
        ->and($csvImport->mapping['selection']['email'])->toBe('E-Mail')
        ->and($csvImport->mapping['selection']['current_position'])->toBe('Aktuelle Position')
        ->and($csvImport->mapping['selection']['first_name'])->toBe('Vorname')
        ->and($xlsxImport->mapping)
        ->toMatchArray([
            'headers' => ['E-Mail', 'Aktuelle Position', 'Vorname'],
            'preview' => [[
                'E-Mail' => 'joao@example.com',
                'Aktuelle Position' => 'LKW-Fahrer',
                'Vorname' => 'João',
            ]],
        ]);

    $mapping = [
        'first_name' => 'Vorname',
        'last_name' => null,
        'email' => 'E-Mail',
        'current_position' => 'Aktuelle Position',
        'desired_position' => null,
        'current_country_code' => null,
        'experience_years' => null,
        'language_level' => null,
    ];
    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.candidate-imports.map', $csvImport), ['mapping' => $mapping])
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(
        ProcessCandidateImport::class,
        fn (ProcessCandidateImport $job): bool => $job->importId === $csvImport->getKey(),
    );
    expect($csvImport->fresh()?->status)->toBe('queued');

    $duplicateImport = CandidateImport::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'original_filename' => 'doppelt.csv',
        'disk' => 'private',
        'storage_path' => 'doppelt.csv',
        'status' => 'awaiting_mapping',
        'mapping' => [
            'headers' => ['E-Mail', 'Aktuelle Position'],
            'preview' => [],
            'selection' => [],
        ],
    ]);
    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.candidate-imports.map', $duplicateImport), [
            'mapping' => [
                ...$mapping,
                'first_name' => null,
                'email' => 'E-Mail',
                'current_position' => 'E-Mail',
            ],
        ])
        ->assertUnprocessable();

    $foreignImport = CandidateImport::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignCompany->memberships()->value('user_id'),
        'original_filename' => 'fremd.csv',
        'disk' => 'private',
        'storage_path' => 'fremd.csv',
        'status' => 'awaiting_mapping',
        'mapping' => ['headers' => ['E-Mail', 'Aktuelle Position']],
    ]);
    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.candidate-imports.map', $foreignImport), [
            'mapping' => $mapping,
        ])
        ->assertNotFound();
});

it('rejects formulas and duplicate imports while keeping duplicate checks tenant-scoped', function () {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinBulkEmployer();
    $mapping = [
        'first_name' => 'Vorname',
        'last_name' => null,
        'email' => 'E-Mail',
        'current_position' => 'Position',
        'desired_position' => null,
        'current_country_code' => null,
        'experience_years' => null,
        'language_level' => null,
    ];

    $previousImport = erinCandidateImport(
        $company,
        $owner,
        "E-Mail,Position\nalt@example.com,Pflege\n",
        $mapping,
    );
    CandidateImportRow::query()->create([
        'candidate_import_id' => $previousImport->getKey(),
        'company_id' => $company->getKey(),
        'row_number' => 2,
        'email' => 'alt@example.com',
        'current_position' => 'Pflege',
        'status' => 'imported',
    ]);
    $foreignImport = erinCandidateImport(
        $foreignCompany,
        $foreignOwner,
        "E-Mail,Position\ngeteilt@example.com,Pflege\n",
        $mapping,
    );
    CandidateImportRow::query()->create([
        'candidate_import_id' => $foreignImport->getKey(),
        'company_id' => $foreignCompany->getKey(),
        'row_number' => 2,
        'email' => 'geteilt@example.com',
        'current_position' => 'Pflege',
        'status' => 'imported',
    ]);

    $import = erinCandidateImport(
        $company,
        $owner,
        implode("\n", [
            'Vorname,E-Mail,Position',
            'Marta,neu@example.com,Elektrikerin',
            'Maria,neu@example.com,Pflegefachkraft',
            'Ana,formel@example.com,=HYPERLINK("https://example.test")',
            'Eva,alt@example.com,Pflegefachkraft',
            'Joana,geteilt@example.com,LKW-Fahrerin',
            '',
        ]),
        $mapping,
    );

    (new ProcessCandidateImport($import->getKey()))->handle(
        app(CandidateImportReader::class),
        app(ActivityRecorder::class),
    );

    $rows = $import->rows()->orderBy('row_number')->get();

    expect($import->fresh())
        ->status->toBe('completed_with_errors')
        ->total_rows->toBe(5)
        ->imported_rows->toBe(2)
        ->failed_rows->toBe(3)
        ->and($rows->firstWhere('email', 'neu@example.com')?->status)->toBe('imported')
        ->and($rows->where('email', 'neu@example.com')->last()?->errors['email'][0])
        ->toContain('mehrfach')
        ->and($rows->firstWhere('email', 'formel@example.com')?->errors['row'][0])
        ->toContain('Formeln')
        ->and($rows->firstWhere('email', 'alt@example.com')?->errors['email'][0])
        ->toContain('bereits früher')
        ->and($rows->firstWhere('email', 'geteilt@example.com')?->status)->toBe('imported');
});

it('rejects formula cells from XLSX imports', function () {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    $xlsxPath = tempnam(sys_get_temp_dir(), 'erin-formula-xlsx-');
    expect($xlsxPath)->not->toBeFalse();
    $writer = new Writer;
    $writer->openToFile($xlsxPath);
    $writer->addRow(Row::fromValues(['E-Mail', 'Position']));
    $writer->addRow(new Row([
        Cell::fromValue('formula@example.com'),
        new FormulaCell('=WEBSERVICE("https://example.test/export")'),
    ]));
    $writer->close();
    $contents = file_get_contents($xlsxPath);
    @unlink($xlsxPath);
    expect($contents)->not->toBeFalse();

    $import = erinCandidateImport(
        $company,
        $owner,
        $contents,
        [
            'email' => 'E-Mail',
            'current_position' => 'Position',
        ],
        'kandidaten.xlsx',
    );

    (new ProcessCandidateImport($import->getKey()))->handle(
        app(CandidateImportReader::class),
        app(ActivityRecorder::class),
    );

    $row = $import->rows()->sole();

    expect($import->fresh())
        ->status->toBe('completed_with_errors')
        ->imported_rows->toBe(0)
        ->failed_rows->toBe(1)
        ->and($row->status)->toBe('invalid')
        ->and($row->errors['row'][0])->toContain('Formeln');
});

it('rejects nonnumeric experience values while accepting decimal commas', function () {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    $import = erinCandidateImport(
        $company,
        $owner,
        implode("\n", [
            'E-Mail;Position;Erfahrung',
            'valid@example.com;Elektrikerin;1,5',
            'invalid@example.com;Pflegefachkraft;abc',
            '',
        ]),
        [
            'email' => 'E-Mail',
            'current_position' => 'Position',
            'experience_years' => 'Erfahrung',
        ],
    );

    (new ProcessCandidateImport($import->getKey()))->handle(
        app(CandidateImportReader::class),
        app(ActivityRecorder::class),
    );

    $validRow = $import->rows()->where('email', 'valid@example.com')->sole();
    $invalidRow = $import->rows()->where('email', 'invalid@example.com')->sole();

    expect($import->fresh())
        ->status->toBe('completed_with_errors')
        ->total_rows->toBe(2)
        ->imported_rows->toBe(1)
        ->failed_rows->toBe(1)
        ->and($validRow)
        ->status->toBe('imported')
        ->experience_years->toBe('1.5')
        ->and($invalidRow)
        ->status->toBe('invalid')
        ->experience_years->toBeNull()
        ->and($invalidRow->payload['experience_years'])->toBe('abc')
        ->and($invalidRow->errors['experience_years'][0])
        ->toContain('Berufserfahrung');
});

it('stops candidate imports after the five-hundredth data row', function () {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinBulkEmployer();
    $mapping = [
        'email' => 'E-Mail',
        'current_position' => 'Position',
    ];
    $rows = ['E-Mail,Position'];
    foreach (range(1, 501) as $index) {
        $rows[] = "candidate{$index}@example.com,Elektriker/in";
    }
    $import = erinCandidateImport(
        $company,
        $owner,
        implode("\n", $rows)."\n",
        $mapping,
    );
    $job = new ProcessCandidateImport($import->getKey());

    try {
        $job->handle(app(CandidateImportReader::class), app(ActivityRecorder::class));
        $this->fail('Ein Import mit 501 Datensätzen muss abgelehnt werden.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Die Datei enthält mehr als 500 Datensätze.');
        $job->failed($exception);
    }

    expect($import->fresh())
        ->status->toBe('failed')
        ->and($import->fresh()?->errors['file'][0])
        ->toBe('Die Datei enthält mehr als 500 Datensätze.')
        ->and($import->rows()->count())->toBe(0)
        ->and($import->fresh()?->total_rows)->toBe(0)
        ->and($import->fresh()?->imported_rows)->toBe(0)
        ->and($import->fresh()?->failed_rows)->toBe(0);
});
