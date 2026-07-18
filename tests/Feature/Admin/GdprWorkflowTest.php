<?php

use App\Enums\GdprRequestStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessGdprRequest;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\GdprRequest;
use App\Models\User;
use App\Services\Privacy\GdprErasure;
use App\Services\Privacy\GdprExportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('requires two different superadmins before dispatching a real export', function () {
    Queue::fake();
    $verifier = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $approver = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $subject = User::factory()->create();
    $request = GdprRequest::query()->create([
        'user_id' => $subject->getKey(),
        'type' => 'export',
        'status' => GdprRequestStatus::Requested,
    ]);

    $this->actingAs($verifier)->patch(route('admin.gdpr-requests.update', $request), [
        'type' => 'export',
        'status' => 'verified',
        'reason' => 'Identität durch Rückruf bestätigt',
    ])->assertRedirect();

    $this->actingAs($verifier)->patch(route('admin.gdpr-requests.update', $request), [
        'type' => 'export',
        'status' => 'processing',
        'reason' => 'Unzulässige Eigenfreigabe',
    ])->assertSessionHasErrors('status');

    $this->actingAs($approver)->patch(route('admin.gdpr-requests.update', $request), [
        'type' => 'export',
        'status' => 'processing',
        'reason' => 'Vier-Augen-Freigabe abgeschlossen',
    ])->assertRedirect();

    expect($request->refresh()->verified_by)->toBe($verifier->getKey())
        ->and($request->approved_by)->toBe($approver->getKey())
        ->and($request->status)->toBe(GdprRequestStatus::Processing);
    Queue::assertPushed(ProcessGdprRequest::class);
});

it('creates an encrypted private export and permits one audited download', function () {
    Storage::fake('private');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $subject = CandidateProfile::factory()->create()->user;
    $request = GdprRequest::query()->create([
        'user_id' => $subject->getKey(),
        'verified_by' => $admin->getKey(),
        'approved_by' => $admin->getKey(),
        'type' => 'export',
        'status' => GdprRequestStatus::Processing,
        'verified_at' => now(),
    ]);

    (new ProcessGdprRequest($request->getKey()))->handle(
        app(GdprExportBuilder::class),
        app(GdprErasure::class),
    );

    $request->refresh();
    expect($request->status)->toBe(GdprRequestStatus::Completed)
        ->and($request->export_path)->not->toBeNull()
        ->and(Storage::disk('private')->get($request->export_path))
        ->not->toContain($subject->email);

    $url = URL::temporarySignedRoute(
        'admin.gdpr-requests.download',
        now()->addMinutes(5),
        ['gdprRequest' => $request],
    );
    $this->actingAs($admin)->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'application/json; charset=UTF-8')
        ->assertDownload();

    $this->actingAs($admin)->get($url)->assertGone();
});

it('blocks deletion under legal hold and pseudonymizes access, search and private files', function () {
    Storage::fake('private');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $profile = CandidateProfile::factory()->create();
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->getKey(),
        'type' => 'cv',
        'title' => 'Lebenslauf',
        'status' => 'uploaded',
        'disk' => 'private',
        'path' => 'candidates/private-cv.pdf',
        'original_name' => 'Lebenslauf.pdf',
        'scan_result' => 'clean',
    ]);
    Storage::disk('private')->put($document->path, 'sensitive');
    $request = GdprRequest::query()->create([
        'user_id' => $profile->user_id,
        'verified_by' => $admin->getKey(),
        'approved_by' => $admin->getKey(),
        'type' => 'delete',
        'status' => GdprRequestStatus::Processing,
        'verified_at' => now(),
        'legal_hold' => true,
        'legal_hold_reason' => 'Offenes arbeitsrechtliches Verfahren bis 2027',
    ]);

    expect(fn () => app(GdprErasure::class)->erase($request, $profile->user))
        ->toThrow(DomainException::class);

    $request->update(['legal_hold' => false, 'legal_hold_reason' => null]);
    $result = app(GdprErasure::class)->erase($request, $profile->user);

    $profile->refresh();
    expect($result['result'])->toBe('pseudonymized')
        ->and($profile->published_at)->toBeNull()
        ->and($profile->first_name)->toBe('Gelöscht')
        ->and($profile->user->refresh()->email)->toEndWith('@erased.invalid')
        ->and($profile->user->status->value)->toBe('blocked')
        ->and(CandidateDocument::withTrashed()->whereKey($document->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('private')->exists($document->path))->toBeFalse();
});

it('records a failed workflow and can be retried without duplicating a completed export', function () {
    Storage::fake('private');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $subject = User::factory()->create();
    $request = GdprRequest::query()->create([
        'user_id' => $subject->getKey(),
        'verified_by' => $admin->getKey(),
        'approved_by' => $admin->getKey(),
        'type' => 'export',
        'status' => GdprRequestStatus::Processing,
    ]);
    $job = new ProcessGdprRequest($request->getKey());
    $job->handle(app(GdprExportBuilder::class), app(GdprErasure::class));
    $firstPath = $request->refresh()->export_path;

    $job->handle(app(GdprExportBuilder::class), app(GdprErasure::class));

    expect($request->refresh()->export_path)->toBe($firstPath)
        ->and(Storage::disk('private')->allFiles('gdpr/exports'))->toHaveCount(1);
});
