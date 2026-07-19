<?php

namespace App\Services\Documents;

use App\Models\UploadReservation;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function userQuotaBytes(?User $user = null): int
    {
        if ($user?->storage_quota_bytes !== null) {
            return max(1, (int) $user->storage_quota_bytes);
        }

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
    ): UploadReservation {
        $files = $files instanceof UploadedFile ? [$files] : $files;
        $incomingBytes = array_sum(array_map(
            static fn (UploadedFile $file): int => max(0, (int) $file->getSize()),
            $files,
        ));

        $reservation = DB::transaction(function () use (
            $user,
            $incomingBytes,
            $reclaimBytes,
            $field,
        ): UploadReservation {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            UploadReservation::query()->where('expires_at', '<=', now())->delete();

            $usedBytes = $this->usedBytesFor($lockedUser);
            $reservations = UploadReservation::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('expires_at', '>', now())
                ->get(['bytes', 'reclaim_bytes']);
            $reservedBytes = $reservations->sum(
                static fn (UploadReservation $item): int => max(
                    0,
                    (int) $item->bytes - (int) $item->reclaim_bytes,
                ),
            );
            $quotaBytes = $this->userQuotaBytes($lockedUser);

            if (max(0, $usedBytes - $reclaimBytes) + $reservedBytes + $incomingBytes > $quotaBytes) {
                throw ValidationException::withMessages([
                    $field => __('Dein Speicherlimit ist erreicht. Verwendet: :used MB, reserviert: :reserved MB, Limit: :limit MB.', [
                        'used' => number_format($usedBytes / 1024 / 1024, 1, ',', '.'),
                        'reserved' => number_format($reservedBytes / 1024 / 1024, 1, ',', '.'),
                        'limit' => number_format($quotaBytes / 1024 / 1024, 0, ',', '.'),
                    ]),
                ]);
            }

            return UploadReservation::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $lockedUser->getKey(),
                'bytes' => $incomingBytes,
                'reclaim_bytes' => max(0, $reclaimBytes),
                'expires_at' => now()->addMinutes(15),
            ]);
        }, 3);

        app()->terminating(fn (): bool => (bool) $reservation->delete());

        return $reservation;
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
     * @return array{
     *     used_bytes: int,
     *     quota_bytes: int,
     *     remaining_bytes: int,
     *     percentage: int,
     *     reserved_bytes: int,
     *     custom_quota: bool
     * }
     */
    public function usageFor(User $user): array
    {
        $used = $this->usedBytesFor($user);
        $quota = $this->userQuotaBytes($user);
        $reserved = (int) UploadReservation::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->sum('bytes');

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'remaining_bytes' => max(0, $quota - $used),
            'percentage' => min(100, (int) round($used / max(1, $quota) * 100)),
            'reserved_bytes' => $reserved,
            'custom_quota' => $user->storage_quota_bytes !== null,
        ];
    }

    /**
     * @param  iterable<User>  $users
     * @return array<int, array<string, int|bool>>
     */
    public function usageForUsers(iterable $users): array
    {
        $users = collect($users)->keyBy(fn (User $user): int => $user->getKey());
        $ids = $users->keys()->all();
        $totals = $users->mapWithKeys(fn (User $user): array => [$user->getKey() => 0])->all();

        $add = static function ($rows) use (&$totals): void {
            foreach ($rows as $row) {
                $totals[(int) $row->user_id] += (int) $row->total;
            }
        };

        $add(DB::table('candidate_documents')
            ->join('candidate_profiles', 'candidate_profiles.id', '=', 'candidate_documents.candidate_profile_id')
            ->whereIn('candidate_profiles.user_id', $ids)
            ->whereNull('candidate_documents.deleted_at')
            ->groupBy('candidate_profiles.user_id')
            ->get(['candidate_profiles.user_id', DB::raw('SUM(candidate_documents.size_bytes) AS total')]));
        $add(DB::table('candidate_profiles')->whereIn('user_id', $ids)
            ->groupBy('user_id')->get(['user_id', DB::raw('SUM(profile_photo_size_bytes) AS total')]));
        $add(DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id')
            ->whereIn('messages.sender_id', $ids)->groupBy('messages.sender_id')
            ->get(['messages.sender_id AS user_id', DB::raw('SUM(message_attachments.size_bytes) AS total')]));

        foreach ([
            ['support_ticket_attachments', 'uploaded_by'],
            ['company_media', 'uploaded_by'],
            ['job_media', 'uploaded_by'],
            ['candidate_imports', 'created_by'],
        ] as [$table, $owner]) {
            $add(DB::table($table)->whereIn($owner, $ids)->groupBy($owner)
                ->get(["{$owner} AS user_id", DB::raw('SUM(size_bytes) AS total')]));
        }

        $reserved = UploadReservation::query()
            ->whereIn('user_id', $ids)
            ->where('expires_at', '>', now())
            ->selectRaw('user_id, SUM(bytes) AS total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return $users->mapWithKeys(function (User $user) use ($totals, $reserved): array {
            $used = $totals[$user->getKey()];
            $quota = $this->userQuotaBytes($user);

            return [$user->getKey() => [
                'used_bytes' => $used,
                'quota_bytes' => $quota,
                'remaining_bytes' => max(0, $quota - $used),
                'percentage' => min(100, (int) round($used / max(1, $quota) * 100)),
                'reserved_bytes' => (int) ($reserved[$user->getKey()] ?? 0),
                'custom_quota' => $user->storage_quota_bytes !== null,
            ]];
        })->all();
    }
}
