<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCandidateImport;
use App\Models\CandidateImport;
use App\Services\Companies\CurrentCompany;
use App\Services\Documents\ClamAvScanner;
use App\Services\Imports\CandidateImportReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CandidateImportController extends Controller
{
    /**
     * @var list<string>
     */
    private const FIELDS = [
        'first_name',
        'last_name',
        'email',
        'current_position',
        'desired_position',
        'current_country_code',
        'experience_years',
        'language_level',
    ];

    public function store(
        Request $request,
        CurrentCompany $currentCompany,
        ClamAvScanner $scanner,
        CandidateImportReader $reader,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $file = $validated['file'];
        $path = $file->store("candidate-imports/{$company->getKey()}", 'private');
        abort_if($path === false, 500, __('Die Importdatei konnte nicht privat gespeichert werden.'));
        $stream = Storage::disk('private')->readStream($path);
        abort_unless(is_resource($stream), 500);

        try {
            $result = $scanner->scan($stream);
        } catch (Throwable $exception) {
            report($exception);
            Storage::disk('private')->delete($path);

            return back()->withErrors([
                'file' => __('Die Sicherheitsprüfung ist momentan nicht verfügbar. Bitte versuche es später erneut.'),
            ]);
        } finally {
            fclose($stream);
        }

        if ($result !== 'clean') {
            Storage::disk('private')->delete($path);

            return back()->withErrors([
                'file' => __('Die Importdatei wurde aus Sicherheitsgründen abgelehnt.'),
            ]);
        }

        try {
            $localPath = $this->localCopy($path);
            $preview = $reader->preview($localPath, $file->getClientOriginalName());
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);

            return back()->withErrors(['file' => $exception->getMessage()]);
        } finally {
            if (isset($localPath)) {
                @unlink($localPath);
            }
        }

        if ($preview['headers'] === []) {
            Storage::disk('private')->delete($path);

            return back()->withErrors(['file' => __('Die Importdatei enthält keine Kopfzeile.')]);
        }

        /** @var CandidateImport $import */
        $import = CandidateImport::query()->create([
            'company_id' => $company->getKey(),
            'created_by' => $user->getKey(),
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'private',
            'storage_path' => $path,
            'status' => 'awaiting_mapping',
            'mapping' => [
                'headers' => $preview['headers'],
                'preview' => $preview['rows'],
                'selection' => $this->suggestMapping($preview['headers']),
            ],
        ]);

        return back()->with(
            'success',
            __('Die Datei wurde geprüft. Ordne jetzt die Spalten für Import :id zu.', ['id' => $import->getKey()]),
        );
    }

    public function map(
        Request $request,
        CandidateImport $candidateImport,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($candidateImport->company_id === $company->getKey(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        abort_unless($candidateImport->status === 'awaiting_mapping', 422);
        $storedMapping = $candidateImport->mapping;
        $headers = is_array($storedMapping['headers'] ?? null)
            ? array_values(array_filter(
                $storedMapping['headers'],
                static fn (mixed $header): bool => is_string($header),
            ))
            : [];
        $rules = [];

        foreach (self::FIELDS as $field) {
            $rules["mapping.{$field}"] = [
                in_array($field, ['email', 'current_position'], true) ? 'required' : 'nullable',
                'string',
                Rule::in($headers),
            ];
        }

        $validated = $request->validate($rules);
        /** @var array<string, string|null> $selection */
        $selection = $validated['mapping'];
        $mappedHeaders = array_values(array_filter(
            $selection,
            static fn (?string $header): bool => filled($header),
        ));
        if (count($mappedHeaders) !== count(array_unique($mappedHeaders))) {
            abort(422, __('Jede Quellspalte darf nur einem Zielfeld zugeordnet werden.'));
        }
        $candidateImport->update([
            'mapping' => $selection,
            'status' => 'queued',
        ]);
        ProcessCandidateImport::dispatch($candidateImport->getKey());

        return back()->with('success', __('Der Kandidatenimport wurde gestartet.'));
    }

    public function destroy(
        Request $request,
        CandidateImport $candidateImport,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($candidateImport->company_id === $company->getKey(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        abort_if(in_array($candidateImport->status, ['queued', 'processing'], true), 422);
        Storage::disk($candidateImport->disk)->delete($candidateImport->storage_path);
        $candidateImport->delete();

        return back()->with('success', __('Der Kandidatenimport wurde gelöscht.'));
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');
            if (! is_resource($stream)) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Vorname',
                'Nachname',
                'E-Mail',
                'Aktuelle Position',
                'Wunschposition',
                'Ländercode',
                'Berufserfahrung',
                'Sprachniveau',
            ]);
            fputcsv($stream, [
                'Marta',
                'Nowak',
                'marta.nowak@example.com',
                'Elektrikerin',
                'Industrieelektrikerin',
                'PL',
                '6',
                'B1',
            ]);
            fclose($stream);
        }, 'erin-kandidatenimport-vorlage.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    private function suggestMapping(array $headers): array
    {
        $aliases = [
            'first_name' => ['vorname', 'first name', 'firstname'],
            'last_name' => ['nachname', 'last name', 'lastname'],
            'email' => ['e-mail', 'email', 'mail'],
            'current_position' => ['aktuelle position', 'position', 'current position', 'job'],
            'desired_position' => ['wunschposition', 'desired position', 'zielposition'],
            'current_country_code' => ['ländercode', 'land', 'country', 'country code'],
            'experience_years' => ['berufserfahrung', 'erfahrung', 'experience', 'experience years'],
            'language_level' => ['sprachniveau', 'deutsch', 'language level', 'german level'],
        ];

        return collect($aliases)->mapWithKeys(function (array $needles, string $field) use ($headers): array {
            $match = collect($headers)->first(
                fn (string $header): bool => in_array(mb_strtolower(trim($header)), $needles, true),
            );

            return [$field => $match];
        })->all();
    }

    private function localCopy(string $path): string
    {
        $source = Storage::disk('private')->readStream($path);
        if (! is_resource($source)) {
            throw new RuntimeException('Die private Importdatei konnte nicht geöffnet werden.');
        }

        $localPath = tempnam(sys_get_temp_dir(), 'erin-preview-');
        if ($localPath === false) {
            fclose($source);
            throw new RuntimeException('Für die Vorschau konnte keine temporäre Datei angelegt werden.');
        }

        $target = fopen($localPath, 'wb');
        if (! is_resource($target)) {
            fclose($source);
            @unlink($localPath);
            throw new RuntimeException('Die temporäre Importdatei konnte nicht geöffnet werden.');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $localPath;
    }
}
