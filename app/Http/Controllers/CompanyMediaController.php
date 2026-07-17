<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\CompanyMedia;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyMediaController extends Controller
{
    public function download(
        Request $request,
        CompanyMedia $media,
        AuditLogger $audit,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        $allowed = $user->isPlatformStaff()
            || $user->role === UserRole::Candidate
            || ($user->role === UserRole::Company && $user->belongsToCompany($media->company_id));

        abort_unless($allowed, 403);
        abort_unless($media->scan_result === 'clean', 423, __('Die Datei ist noch nicht sicherheitsgeprüft.'));
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        $audit->record('company.media_downloaded', $media, metadata: [
            'company_id' => $media->company_id,
        ], companyId: $media->company_id);

        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }
}
