<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\JobMedia;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobMediaController extends Controller
{
    public function download(
        Request $request,
        JobMedia $media,
        AuditLogger $audit,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $media->loadMissing('jobPosting');
        $job = $media->jobPosting;
        $allowed = $user->isPlatformStaff()
            || ($user->role === UserRole::Company && $user->belongsToCompany($job->company_id))
            || (
                $user->role === UserRole::Candidate
                && $job->status === JobStatus::Published
                && $job->published_at !== null
            );

        abort_unless($allowed, 403);
        abort_unless($media->scan_result === 'clean', 423, __('Die Datei ist noch nicht sicherheitsgeprüft.'));
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        $audit->record('job.media_downloaded', $media, metadata: [
            'job_posting_id' => $job->getKey(),
        ], companyId: $job->company_id);

        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }
}
