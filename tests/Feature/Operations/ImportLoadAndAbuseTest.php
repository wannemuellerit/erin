<?php

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Jobs\ProcessCandidateImport;
use App\Models\CandidateImport;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\Documents\ClamAvScanner;
use App\Services\Imports\CandidateImportReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function erinOpsImportEmployer(): array
{
    $user = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return compact('user', 'company');
}

function erinOpsXlsxFixture(int $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'erin-load-xlsx-');
    if ($path === false) {
        throw new RuntimeException('Temporäre XLSX-Datei konnte nicht angelegt werden.');
    }

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['E-Mail', 'Position', 'Erfahrung']));
    foreach (range(1, $rows) as $index) {
        $writer->addRow(Row::fromValues([
            "load-{$index}@example.com",
            'Elektriker/in',
            (string) ($index % 40),
        ]));
    }
    $writer->close();
    $contents = file_get_contents($path);
    @unlink($path);

    if (! is_string($contents)) {
        throw new RuntimeException('Temporäre XLSX-Datei konnte nicht gelesen werden.');
    }

    return $contents;
}

/**
 * @param  'csv'|'xlsx'  $format
 */
function erinOpsImportFixture(Company $company, User $user, string $format, int $rows): CandidateImport
{
    $contents = $format === 'xlsx'
        ? erinOpsXlsxFixture($rows)
        : implode("\n", [
            'E-Mail,Position,Erfahrung',
            ...array_map(
                static fn (int $index): string => "load-{$index}@example.com,Elektriker/in,".($index % 40),
                range(1, $rows),
            ),
            '',
        ]);
    $path = "candidate-imports/{$company->getKey()}/load-test.{$format}";
    Storage::disk('private')->put($path, $contents);

    return CandidateImport::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
        'original_filename' => "kandidaten.{$format}",
        'disk' => 'private',
        'storage_path' => $path,
        'status' => 'queued',
        'mapping' => [
            'email' => 'E-Mail',
            'current_position' => 'Position',
            'experience_years' => 'Erfahrung',
        ],
    ]);
}

it('processes the exact five-hundred-row boundary for CSV and XLSX', function (string $format) {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinOpsImportEmployer();
    $import = erinOpsImportFixture($company, $owner, $format, 500);

    (new ProcessCandidateImport($import->getKey()))->handle(
        app(CandidateImportReader::class),
        app(ActivityRecorder::class),
    );

    expect($import->fresh())
        ->status->toBe('completed')
        ->total_rows->toBe(500)
        ->imported_rows->toBe(500)
        ->failed_rows->toBe(0)
        ->and($import->rows()->count())->toBe(500)
        ->and($import->rows()->distinct()->count('email'))->toBe(500);
})->with(['csv', 'xlsx'])->group('ops', 'load');

it('rejects a five-hundred-and-first XLSX row without leaving partial rows', function () {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinOpsImportEmployer();
    $import = erinOpsImportFixture($company, $owner, 'xlsx', 501);
    $job = new ProcessCandidateImport($import->getKey());

    try {
        $job->handle(app(CandidateImportReader::class), app(ActivityRecorder::class));
        $this->fail('Ein XLSX-Import mit 501 Datensätzen muss abgelehnt werden.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('mehr als 500');
        $job->failed($exception);
    }

    expect($import->fresh())
        ->status->toBe('failed')
        ->total_rows->toBe(0)
        ->imported_rows->toBe(0)
        ->failed_rows->toBe(0)
        ->and($import->rows()->count())->toBe(0);
})->group('ops', 'abuse');

it('deletes quarantined uploads when ClamAV reports malware or is unavailable', function (string $result) {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinOpsImportEmployer();
    $scanner = $this->mock(ClamAvScanner::class);

    if ($result === 'unavailable') {
        $scanner->shouldReceive('scan')->once()->andThrow(
            new RuntimeException('ClamAV ist im Test nicht erreichbar.'),
        );
    } else {
        $scanner->shouldReceive('scan')->once()->andReturn('infected');
    }

    $response = $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidate-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent(
                'kandidaten.csv',
                "E-Mail,Position\nprobe@example.com,Elektriker/in\n",
            ),
        ]);

    $response->assertRedirect()->assertSessionHasErrors('file');
    expect(CandidateImport::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with(['infected', 'unavailable'])->group('ops', 'abuse');

it('fails closed when the private object is missing before queue processing', function () {
    Storage::fake('private');
    ['user' => $owner, 'company' => $company] = erinOpsImportEmployer();
    $import = CandidateImport::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'original_filename' => 'verschwunden.csv',
        'disk' => 'private',
        'storage_path' => 'candidate-imports/missing.csv',
        'status' => 'queued',
        'mapping' => ['email' => 'E-Mail', 'current_position' => 'Position'],
    ]);
    $job = new ProcessCandidateImport($import->getKey());

    try {
        $job->handle(app(CandidateImportReader::class), app(ActivityRecorder::class));
        $this->fail('Ein fehlendes privates Objekt muss den Import stoppen.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('nicht geöffnet');
        $job->failed($exception);
    }

    expect($import->fresh())
        ->status->toBe('failed')
        ->and($import->fresh()?->errors['file'][0])->toContain('nicht geöffnet')
        ->and($import->rows()->count())->toBe(0);
})->group('ops', 'abuse');
