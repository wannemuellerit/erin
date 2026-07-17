<?php

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMedia;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('never exposes company storage paths and only serves clean media to authorized audiences', function () {
    Storage::fake('private');
    $owner = User::factory()->create(['role' => UserRole::Company]);
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    $foreignCompanyUser = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);
    $path = "companies/{$company->getKey()}/profile/logo.png";
    Storage::disk('private')->put($path, 'private logo');
    $media = CompanyMedia::query()->create([
        'company_id' => $company->getKey(),
        'uploaded_by' => $owner->getKey(),
        'type' => 'logo',
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'logo.png',
        'mime_type' => 'image/png',
        'size_bytes' => 12,
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $company->update(['logo_media_id' => $media->getKey()]);

    $this->actingAs($owner)
        ->get(route('employer.company'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employer/CompanyProfile')
            ->where('company.media.0.id', $media->getKey())
            ->where('company.media.0.scan_result', 'clean')
            ->has('company.media.0.download_url')
            ->missing('company.media.0.path')
            ->missing('company.media.0.disk'));

    $url = URL::temporarySignedRoute(
        'companies.media.download',
        now()->addMinutes(10),
        ['media' => $media],
    );
    $this->actingAs($candidate)->get($url)
        ->assertOk()
        ->assertDownload('logo.png');
    $this->actingAs($foreignCompanyUser)->get($url)->assertForbidden();

    $media->update(['scan_result' => 'pending']);
    $this->actingAs($candidate)->get($url)->assertStatus(423);
});
