<?php

namespace App\Http\Controllers\Candidate;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Http\Controllers\Controller;
use App\Jobs\ScanCandidateDocument;
use App\Jobs\ScanCandidateProfilePhoto;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Skill;
use App\Services\Audit\AuditLogger;
use App\Services\Candidates\ProfileCompletenessCalculator;
use App\Services\Documents\UploadPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(Request $request, ProfileCompletenessCalculator $completeness): Response
    {
        $profile = $request->user()?->candidateProfile()
            ->with(['occupation', 'experiences', 'educations', 'skills', 'languages', 'documents'])
            ->firstOrFail();

        return Inertia::render('candidate/Profile', [
            'profile' => [
                ...Arr::except($profile->toArray(), ['documents']),
                'profile_photo_url' => $profile->profile_photo_scan_result === 'clean'
                    && filled($profile->profile_photo_path)
                    ? URL::temporarySignedRoute(
                        'candidate.profile.photo',
                        now()->addMinutes(15),
                    )
                    : null,
                'documents' => $profile->documents->map(
                    fn (CandidateDocument $document): array => [
                        'id' => $document->getKey(),
                        'type' => $document->type->value,
                        'title' => $document->title,
                        'original_name' => $document->original_name,
                        'mime_type' => $document->mime_type,
                        'size_bytes' => $document->size_bytes,
                        'status' => $document->status->value,
                        'scan_result' => $document->scan_result,
                        'rejection_reason' => $document->rejection_reason,
                        'expires_at' => $document->expires_at?->toIso8601String(),
                        'created_at' => $document->created_at?->toIso8601String(),
                        'download_url' => $document->scan_result === 'clean'
                            ? URL::temporarySignedRoute(
                                'documents.download',
                                now()->addMinutes(15),
                                ['document' => $document],
                            )
                            : null,
                    ],
                )->values(),
            ],
            'profile_status' => $this->recalculate($profile, $completeness, false),
            'occupations' => Occupation::query()->where('is_active', true)->orderBy('name_de')->get(),
            'skills' => Skill::query()->where('is_active', true)->orderBy('name_de')->get(),
            'languages' => Language::query()->orderBy('name_de')->get(),
            'document_types' => collect(CandidateDocumentType::cases())->map->value,
        ]);
    }

    public function uploadPhoto(
        Request $request,
        AuditLogger $audit,
        UploadPolicy $uploads,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.$uploads->maxFileKilobytes(10240),
                'dimensions:max_width=10000,max_height=10000',
            ],
        ]);
        $photo = $request->file('photo');
        abort_if($photo === null, 422);
        $user = $request->user();
        abort_if($user === null, 401);
        $uploads->assertCanStore(
            $user,
            $photo,
            'photo',
            (int) ($profile->profile_photo_size_bytes ?? 0),
        );
        $path = $photo->store("candidates/{$profile->getKey()}/profile/quarantine", 'private');
        abort_if($path === false, 500, __('Das Profilbild konnte nicht privat gespeichert werden.'));
        $previousQuarantine = $profile->profile_photo_quarantine_path;

        try {
            $profile->update([
                'profile_photo_quarantine_path' => $path,
                'profile_photo_disk' => 'private',
                'profile_photo_original_name' => $photo->getClientOriginalName(),
                'profile_photo_mime_type' => $photo->getMimeType(),
                'profile_photo_size_bytes' => $photo->getSize(),
                'profile_photo_scan_result' => 'pending',
                'profile_photo_scan_completed_at' => null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        if (filled($previousQuarantine) && $previousQuarantine !== $path) {
            Storage::disk('private')->delete((string) $previousQuarantine);
        }
        ScanCandidateProfilePhoto::dispatch($profile->getKey(), $path);
        $audit->record('candidate.profile_photo_uploaded', $profile, after: [
            'mime_type' => $profile->profile_photo_mime_type,
            'size_bytes' => $profile->profile_photo_size_bytes,
            'scan_result' => 'pending',
        ]);

        return back()->with('success', __('Dein Profilbild wird sicher geprüft.'));
    }

    public function deletePhoto(
        Request $request,
        ProfileCompletenessCalculator $completeness,
        AuditLogger $audit,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        $paths = array_values(array_filter([
            $profile->profile_photo_path,
            $profile->profile_photo_quarantine_path,
        ], 'is_string'));
        Storage::disk($profile->profile_photo_disk ?: 'private')->delete($paths);
        $before = [
            'had_photo' => filled($profile->profile_photo_path),
            'scan_result' => $profile->profile_photo_scan_result,
        ];
        $profile->update([
            'profile_photo_path' => null,
            'profile_photo_quarantine_path' => null,
            'profile_photo_original_name' => null,
            'profile_photo_mime_type' => null,
            'profile_photo_size_bytes' => null,
            'profile_photo_scan_result' => null,
            'profile_photo_scan_completed_at' => null,
        ]);
        $this->recalculate($profile, $completeness, true);
        $audit->record('candidate.profile_photo_deleted', $profile, before: $before);

        return back()->with('success', __('Dein Profilbild wurde gelöscht.'));
    }

    public function photo(Request $request): StreamedResponse
    {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        abort_unless(
            $profile->profile_photo_scan_result === 'clean'
            && filled($profile->profile_photo_path),
            404,
        );
        $disk = Storage::disk($profile->profile_photo_disk ?: 'private');
        $path = (string) $profile->profile_photo_path;
        abort_unless($disk->exists($path), 404);
        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $profile->profile_photo_mime_type ?: 'image/jpeg',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => 'inline; filename="profilbild.jpg"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function update(
        Request $request,
        ProfileCompletenessCalculator $completeness,
        AuditLogger $audit,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:40'],
            'nationality_country_code' => ['nullable', 'string', 'size:2'],
            'current_country_code' => ['required', 'string', 'size:2'],
            'current_city' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'summary' => ['required', 'string', 'max:5000'],
            'occupation_id' => ['required', 'exists:occupations,id'],
            'current_position' => ['nullable', 'string', 'max:180'],
            'desired_position' => ['required', 'string', 'max:180'],
            'experience_years' => ['required', 'numeric', 'min:0', 'max:60'],
            'highest_qualification' => ['nullable', 'string', 'max:180'],
            'driving_licenses' => ['array'],
            'driving_licenses.*' => ['string', 'max:10'],
            'travel_ready' => ['boolean'],
            'relocation_ready' => ['boolean'],
            'available_from' => ['nullable', 'date'],
            'salary_expectation_cents' => ['nullable', 'integer', 'min:0'],
            'salary_currency' => ['required', Rule::in(['EUR'])],
            'employment_preferences' => ['array'],
            'employment_preferences.*' => [Rule::in(['full_time', 'part_time', 'temporary', 'permanent'])],
            'weekly_hours' => ['nullable', 'integer', 'min:1', 'max:80'],
            'requires_visa' => ['boolean'],
            'has_work_permit' => ['boolean'],
            'skills' => ['array'],
            'skills.*.id' => ['required', 'exists:skills,id'],
            'skills.*.proficiency' => ['nullable', 'integer', 'min:1', 'max:5'],
            'skills.*.experience_years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'languages' => ['array'],
            'languages.*.id' => ['required', 'exists:languages,id'],
            'languages.*.level' => ['required', Rule::in(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'])],
            'experiences' => ['array'],
            'experiences.*.employer' => ['required', 'string', 'max:180'],
            'experiences.*.position' => ['required', 'string', 'max:180'],
            'experiences.*.country_code' => ['nullable', 'string', 'size:2'],
            'experiences.*.started_at' => ['required', 'date'],
            'experiences.*.ended_at' => ['nullable', 'date'],
            'experiences.*.is_current' => ['boolean'],
            'experiences.*.description' => ['nullable', 'string', 'max:3000'],
            'educations' => ['array'],
            'educations.*.institution' => ['required', 'string', 'max:180'],
            'educations.*.qualification' => ['required', 'string', 'max:180'],
            'educations.*.field' => ['nullable', 'string', 'max:180'],
            'educations.*.country_code' => ['nullable', 'string', 'size:2'],
            'educations.*.started_at' => ['nullable', 'date'],
            'educations.*.completed_at' => ['nullable', 'date'],
            'educations.*.description' => ['nullable', 'string', 'max:3000'],
        ]);
        $before = $profile->toArray();

        DB::transaction(function () use ($profile, $validated, $completeness): void {
            $profile->update(Arr::except($validated, ['skills', 'languages', 'experiences', 'educations']));
            /** @var list<array{id: int, proficiency?: int|null, experience_years?: float|int|null}> $skills */
            $skills = is_array($validated['skills'] ?? null) ? array_values($validated['skills']) : [];
            $skillSync = [];
            foreach ($skills as $skill) {
                $skillSync[$skill['id']] = [
                    'proficiency' => $skill['proficiency'] ?? null,
                    'experience_years' => $skill['experience_years'] ?? null,
                    'is_verified' => false,
                ];
            }
            $profile->skills()->sync($skillSync);
            /** @var list<array{id: int, level: string}> $languages */
            $languages = is_array($validated['languages'] ?? null) ? array_values($validated['languages']) : [];
            $languageSync = [];
            foreach ($languages as $language) {
                $languageSync[$language['id']] = ['level' => $language['level'], 'is_verified' => false];
            }
            $profile->languages()->sync($languageSync);
            $profile->experiences()->delete();
            $profile->experiences()->createMany($validated['experiences'] ?? []);
            $profile->educations()->delete();
            $profile->educations()->createMany($validated['educations'] ?? []);
            $this->recalculate($profile, $completeness, true);
        });

        $audit->record('candidate.profile_updated', $profile, $before, $profile->fresh()->toArray());

        return back()->with('success', __('Dein Profil wurde gespeichert.'));
    }

    public function uploadDocument(
        Request $request,
        ProfileCompletenessCalculator $completeness,
        AuditLogger $audit,
        UploadPolicy $uploads,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        $validated = $request->validate([
            'type' => ['required', Rule::enum(CandidateDocumentType::class)],
            'title' => ['required', 'string', 'max:180'],
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx',
                'max:'.$uploads->maxFileKilobytes(15360),
            ],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $file = $request->file('file');
        abort_if($file === null, 422);
        $user = $request->user();
        abort_if($user === null, 401);
        $uploads->assertCanStore($user, $file);
        $path = $file->store("candidates/{$profile->getKey()}/documents", 'private');
        abort_if($path === false, 500, __('Das Dokument konnte nicht privat gespeichert werden.'));
        $realPath = $file->getRealPath();
        abort_if($realPath === false, 500);

        try {
            $document = $profile->documents()->create([
                'type' => $validated['type'],
                'title' => $validated['title'],
                'disk' => 'private',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $realPath),
                'status' => CandidateDocumentStatus::Uploaded,
                'scan_result' => 'pending',
                'expires_at' => $validated['expires_at'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        ScanCandidateDocument::dispatch($document->getKey());
        $this->recalculate($profile, $completeness, true);
        $audit->record('candidate.document_uploaded', $document, after: [
            'type' => $document->type->value,
            'status' => $document->status->value,
            'sha256' => $document->sha256,
        ]);

        return back()->with('success', __('Dokument wurde hochgeladen und wird auf Schadsoftware geprüft.'));
    }

    public function publish(
        Request $request,
        ProfileCompletenessCalculator $completeness,
        AuditLogger $audit,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        $status = $this->recalculate($profile, $completeness, true);

        if (! $profile->occupation_id || ! $profile->summary || ! $profile->current_country_code) {
            return back()->withErrors(['profile' => __('Beruf, Kurzprofil und aktuelles Land müssen vor der Veröffentlichung ausgefüllt sein.')]);
        }

        $profile->update(['published_at' => $profile->published_at ? null : now()]);
        $audit->record(
            $profile->published_at ? 'candidate.profile_published' : 'candidate.profile_unpublished',
            $profile,
            after: ['published' => $profile->published_at !== null, 'completeness' => $status['percentage']],
        );

        return back()->with('success', $profile->published_at
            ? __('Dein anonymisiertes Profil ist jetzt auffindbar.')
            : __('Dein Profil ist nicht mehr öffentlich auffindbar.'));
    }

    /**
     * @return array{percentage: int, completed: list<string>, missing: list<string>, can_apply: bool}
     */
    private function recalculate(
        CandidateProfile $profile,
        ProfileCompletenessCalculator $calculator,
        bool $persist,
    ): array {
        $profile->loadCount(['experiences', 'skills', 'languages', 'educations']);
        $profile->loadMissing('documents');
        $data = [
            ...$profile->toArray(),
            'work_experiences_count' => $profile->experiences_count,
            'skills_count' => $profile->skills_count,
            'languages_count' => $profile->languages_count,
            'educations_count' => $profile->educations_count,
            'has_cv' => $profile->documents->contains(fn (CandidateDocument $document) => (
                $document->type === CandidateDocumentType::Cv
                && $document->scan_result === 'clean'
            )),
            'has_verified_certificate' => $profile->documents->contains(fn (CandidateDocument $document) => (
                in_array($document->type, [
                    CandidateDocumentType::LanguageCertificate,
                    CandidateDocumentType::Qualification,
                ], true)
                && $document->status === CandidateDocumentStatus::Verified
            )),
        ];
        $result = $calculator->calculate($data);

        if ($persist && $profile->completeness !== $result['percentage']) {
            $profile->updateQuietly(['completeness' => $result['percentage']]);
        }

        return $result;
    }
}
