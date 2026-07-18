<?php

namespace App\Jobs;

use App\Models\CandidateImport;
use App\Models\CandidateImportRow;
use App\Services\Activity\ActivityRecorder;
use App\Services\Imports\CandidateImportReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ProcessCandidateImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 180];

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(
        CandidateImportReader $reader,
        ActivityRecorder $activity,
    ): void {
        /** @var CandidateImport $import */
        $import = CandidateImport::query()->with(['company', 'creator'])->findOrFail($this->importId);
        if (! in_array($import->status, ['queued', 'processing'], true)) {
            return;
        }

        $import->update([
            'status' => 'processing',
            'started_at' => $import->started_at ?? now(),
            'errors' => null,
        ]);

        $path = $this->localCopy($import);
        $mapping = is_array($import->mapping) ? $import->mapping : [];
        $seenEmails = [];
        $total = 0;
        $imported = 0;
        $failed = 0;

        try {
            foreach ($reader->mappedRows($path, $import->original_filename, $mapping) as $rowNumber => $data) {
                $total++;
                if ($total > 500) {
                    throw new RuntimeException('Die Datei enthält mehr als 500 Datensätze.');
                }

                $experienceYearsInput = $this->decimalInput($data['experience_years'] ?? null);
                $normalized = [
                    'first_name' => $this->clean($data['first_name'] ?? null),
                    'last_name' => $this->clean($data['last_name'] ?? null),
                    'email' => mb_strtolower((string) $this->clean($data['email'] ?? null)),
                    'current_position' => $this->clean($data['current_position'] ?? null),
                    'desired_position' => $this->clean($data['desired_position'] ?? null),
                    'current_country_code' => mb_strtoupper((string) $this->clean(
                        $data['current_country_code'] ?? null,
                    )),
                    'experience_years' => $this->decimal($experienceYearsInput),
                    'language_level' => mb_strtoupper((string) $this->clean($data['language_level'] ?? null)),
                ];
                $validationData = $normalized;
                $validationData['experience_years'] = $experienceYearsInput;
                $validator = Validator::make($validationData, [
                    'first_name' => ['nullable', 'string', 'max:120'],
                    'last_name' => ['nullable', 'string', 'max:120'],
                    'email' => ['required', 'email:rfc', 'max:255'],
                    'current_position' => ['required', 'string', 'max:160'],
                    'desired_position' => ['nullable', 'string', 'max:160'],
                    'current_country_code' => ['nullable', 'string', 'size:2'],
                    'experience_years' => ['nullable', 'numeric', 'min:0', 'max:80'],
                    'language_level' => ['nullable', 'in:A1,A2,B1,B2,C1,C2'],
                ]);
                $errors = $validator->errors()->toArray();

                if ($this->containsFormula($data)) {
                    $errors['row'][] = 'Formeln und ausführbare Tabellenwerte sind nicht erlaubt.';
                }

                if ($normalized['email'] !== '' && isset($seenEmails[$normalized['email']])) {
                    $errors['email'][] = 'Diese E-Mail-Adresse kommt in der Datei mehrfach vor.';
                }

                if (
                    $normalized['email'] !== ''
                    && CandidateImportRow::query()
                        ->where('company_id', $import->company_id)
                        ->where('email', $normalized['email'])
                        ->where('candidate_import_id', '!=', $import->getKey())
                        ->exists()
                ) {
                    $errors['email'][] = 'Diese E-Mail-Adresse wurde bereits früher importiert.';
                }

                if ($normalized['email'] !== '') {
                    $seenEmails[$normalized['email']] = true;
                }

                DB::transaction(function () use (
                    $import,
                    $rowNumber,
                    $normalized,
                    $data,
                    $errors,
                    &$imported,
                    &$failed,
                ): void {
                    $status = $errors === [] ? 'imported' : 'invalid';
                    CandidateImportRow::query()->updateOrCreate(
                        [
                            'candidate_import_id' => $import->getKey(),
                            'row_number' => $rowNumber,
                        ],
                        [
                            'company_id' => $import->company_id,
                            ...$normalized,
                            'status' => $status,
                            'payload' => $data,
                            'errors' => $errors ?: null,
                        ],
                    );

                    if ($status === 'imported') {
                        $imported++;
                    } else {
                        $failed++;
                    }
                }, 3);
            }
        } finally {
            @unlink($path);
        }

        $import->update([
            'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
            'total_rows' => $total,
            'imported_rows' => $imported,
            'failed_rows' => $failed,
            'completed_at' => now(),
        ]);
        $activity->record(
            'candidate_import.completed',
            $import->creator,
            $import->company,
            $import,
            [
                'filename' => $import->original_filename,
                'imported_rows' => $imported,
                'failed_rows' => $failed,
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            /** @var CandidateImport|null $import */
            $import = CandidateImport::query()->find($this->importId);
            if ($import === null) {
                return;
            }

            $import->rows()->delete();
            $import->update([
                'status' => 'failed',
                'total_rows' => 0,
                'imported_rows' => 0,
                'failed_rows' => 0,
                'errors' => ['file' => [$exception->getMessage()]],
                'completed_at' => now(),
            ]);
        });
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function decimal(?string $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function decimalInput(?string $value): ?string
    {
        $value = $this->clean($value);

        return $value === null ? null : str_replace(',', '.', $value);
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private function containsFormula(array $data): bool
    {
        return collect($data)->contains(
            fn (?string $value): bool => is_string($value)
                && preg_match('/^[=+@]/u', ltrim($value)) === 1,
        );
    }

    private function localCopy(CandidateImport $import): string
    {
        try {
            $source = Storage::disk($import->disk)->readStream($import->storage_path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Die private Importdatei konnte nicht geöffnet werden.',
                previous: $exception,
            );
        }

        if (! is_resource($source)) {
            throw new RuntimeException('Die private Importdatei konnte nicht geöffnet werden.');
        }

        $path = tempnam(sys_get_temp_dir(), 'erin-import-');
        if ($path === false) {
            fclose($source);
            throw new RuntimeException('Für den Import konnte keine temporäre Datei angelegt werden.');
        }

        $target = fopen($path, 'wb');
        if (! is_resource($target)) {
            fclose($source);
            @unlink($path);
            throw new RuntimeException('Die temporäre Importdatei konnte nicht geöffnet werden.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $path;
    }
}
