<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\JobMedia;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('only serves clean private job media through an authorized signed URL', function () {
    Storage::fake('private');
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $job = JobPosting::factory()->create([
        'status' => JobStatus::Published,
        'published_at' => now(),
    ]);
    $path = "companies/{$job->company_id}/jobs/{$job->getKey()}/brochure.pdf";
    Storage::disk('private')->put($path, 'private brochure');
    $media = JobMedia::query()->create([
        'job_posting_id' => $job->getKey(),
        'type' => 'document',
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'brochure.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 17,
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $url = URL::temporarySignedRoute(
        'jobs.media.download',
        now()->addMinutes(10),
        ['media' => $media],
    );

    $this->actingAs($candidate)->get($url)
        ->assertOk()
        ->assertDownload('brochure.pdf');

    $media->update(['scan_result' => 'pending']);
    $this->actingAs($candidate)->get($url)->assertStatus(423);

    $media->update(['scan_result' => 'clean']);
    $job->update(['status' => JobStatus::Draft, 'published_at' => null]);
    $this->actingAs($candidate)->get($url)->assertForbidden();

    $unsigned = route('jobs.media.download', $media);
    $this->actingAs($candidate)->get($unsigned)->assertForbidden();
});
