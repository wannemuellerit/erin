<?php

namespace App\Services\Documents;

use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UploadPolicy
{
    private const DEFAULT_MAX_FILE_MEGABYTES = 50;

    private const DEFAULT_USER_QUOTA_MEGABYTES = 1024;

    public function maxFileKilobytes(?int $contextMaximumKilobytes = null): int
    {
        $configured = max(
            1,
            (int) app(PlatformSettings::class)
                ->get('uploads.max_file_size_mb', self::DEFAULT_MAX_FILE_MEGABYTES),
        ) * 1024;

        return $contextMaximumKilobytes === null
            ? $configured
            : min($configured, $contextMaximumKilobytes);
    }

    public function userQuotaBytes(): int
    {
        return max(
            1,
            (int) app(PlatformSettings::class)
                ->get('uploads.user_quota_mb', self::DEFAULT_USER_QUOTA_MEGABYTES),
        ) * 1024 * 1024;
    }

    /**
     * @param  UploadedFile|list<UploadedFile>  $files
     */
    public function assertCanStore(
        User $user,
        UploadedFile|array $files,
        string $field = 'file',
        int $reclaimBytes = 0,
    ): void {
        $files = $files instanceof UploadedFile ? [$files] : $files;
        $incomingBytes = array_sum(array_map(
            static fn (UploadedFile $file): int => max(0, (int) $file->getSize()),
            $files,
        ));
        $usedBytes = $this->usedBytesFor($user);
        $quotaBytes = $this->userQuotaBytes();

        if (max(0, $usedBytes - $reclaimBytes) + $incomingBytes <= $quotaBytes) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('Dein Speicherlimit ist erreicht. Verwendet: :used MB, Limit: :limit MB.', [
                'used' => number_format($usedBytes / 1024 / 1024, 1, ',', '.'),
                'limit' => number_format($quotaBytes / 1024 / 1024, 0, ',', '.'),
            ]),
        ]);
    }

    public function usedBytesFor(User $user): int
    {
        $userId = $user->getKey();

        $candidateDocuments = (int) DB::table('candidate_documents')
            ->join('candidate_profiles', 'candidate_profiles.id', '=', 'candidate_documents.candidate_profile_id')
            ->where('candidate_profiles.user_id', $userId)
            ->whereNull('candidate_documents.deleted_at')
            ->sum('candidate_documents.size_bytes');
        $profilePhoto = (int) DB::table('candidate_profiles')
            ->where('user_id', $userId)
            ->sum('profile_photo_size_bytes');
        $messages = (int) DB::table('message_attachments')
            ->join('messages', 'messages.id', '=', 'message_attachments.message_id')
            ->where('messages.sender_id', $userId)
            ->sum('message_attachments.size_bytes');
        $support = (int) DB::table('support_ticket_attachments')
            ->where('uploaded_by', $userId)
            ->sum('size_bytes');
        $companyMedia = (int) DB::table('company_media')
            ->where('uploaded_by', $userId)
            ->sum('size_bytes');
        $jobMedia = (int) DB::table('job_media')
            ->where('uploaded_by', $userId)
            ->sum('size_bytes');
        $imports = (int) DB::table('candidate_imports')
            ->where('created_by', $userId)
            ->sum('size_bytes');

        return $candidateDocuments
            + $profilePhoto
            + $messages
            + $support
            + $companyMedia
            + $jobMedia
            + $imports;
    }

    /**
     * @return array{used_bytes: int, quota_bytes: int, remaining_bytes: int, percentage: int}
     */
    public function usageFor(User $user): array
    {
        $used = $this->usedBytesFor($user);
        $quota = $this->userQuotaBytes();

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'remaining_bytes' => max(0, $quota - $used),
            'percentage' => min(100, (int) round($used / max(1, $quota) * 100)),
        ];
    }
}
