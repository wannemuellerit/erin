<?php

namespace App\Jobs;

use App\Models\CandidateProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Candidates\ProfileCompletenessCalculator;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanCandidateProfilePhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(
        public readonly int $profileId,
        public readonly string $quarantinePath,
    ) {}

    public function handle(
        ClamAvScanner $scanner,
        ProfileCompletenessCalculator $completeness,
        AuditLogger $audit,
    ): void {
        $profile = CandidateProfile::query()->findOrFail($this->profileId);
        if ($profile->profile_photo_quarantine_path !== $this->quarantinePath) {
            Storage::disk('private')->delete($this->quarantinePath);

            return;
        }

        $disk = $profile->profile_photo_disk ?: 'private';
        $stream = Storage::disk($disk)->readStream($this->quarantinePath);
        if (! is_resource($stream)) {
            throw new RuntimeException('Das quarantänisierte Profilbild konnte nicht geöffnet werden.');
        }

        try {
            $scanResult = $scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        if ($scanResult === 'infected') {
            Storage::disk($disk)->delete($this->quarantinePath);
            $profile->update([
                'profile_photo_quarantine_path' => null,
                'profile_photo_scan_result' => 'infected',
                'profile_photo_scan_completed_at' => now(),
            ]);
            $audit->record('candidate.profile_photo_rejected', $profile, after: [
                'reason' => 'malware',
            ]);

            return;
        }

        $contents = Storage::disk($disk)->get($this->quarantinePath);
        $imageInfo = getimagesizefromstring($contents);
        if ($imageInfo === false || $imageInfo[0] > 10000 || $imageInfo[1] > 10000 || ($imageInfo[0] * $imageInfo[1]) > 40_000_000) {
            throw new RuntimeException('Das Profilbild besitzt ungültige oder zu große Bildabmessungen.');
        }

        $source = imagecreatefromstring($contents);
        if ($source === false) {
            throw new RuntimeException('Das Profilbild konnte nicht sicher verarbeitet werden.');
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $scale = min(1, 512 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);
            throw new RuntimeException('Das sichere Profilbild-Derivat konnte nicht erzeugt werden.');
        }

        $white = imagecolorallocate($target, 255, 255, 255);
        if ($white === false) {
            imagedestroy($target);
            imagedestroy($source);
            throw new RuntimeException('Das Profilbild-Derivat konnte nicht initialisiert werden.');
        }
        imagefill($target, 0, 0, $white);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );
        ob_start();
        imagejpeg($target, null, 88);
        $derivative = ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);
        if ($derivative === '') {
            throw new RuntimeException('Das sichere Profilbild-Derivat ist leer.');
        }

        $finalPath = "candidates/{$profile->getKey()}/profile/photo-".bin2hex(random_bytes(16)).'.jpg';
        Storage::disk($disk)->put($finalPath, $derivative);
        $oldPath = $profile->profile_photo_path;
        $profile->update([
            'profile_photo_path' => $finalPath,
            'profile_photo_quarantine_path' => null,
            'profile_photo_mime_type' => 'image/jpeg',
            'profile_photo_size_bytes' => strlen($derivative),
            'profile_photo_scan_result' => 'clean',
            'profile_photo_scan_completed_at' => now(),
        ]);
        Storage::disk($disk)->delete($this->quarantinePath);
        if (filled($oldPath) && $oldPath !== $finalPath) {
            Storage::disk($disk)->delete((string) $oldPath);
        }

        $profile->loadCount(['experiences', 'skills', 'languages', 'educations']);
        $profile->updateQuietly([
            'completeness' => $completeness->calculate([
                ...$profile->toArray(),
                'work_experiences_count' => $profile->experiences_count,
                'skills_count' => $profile->skills_count,
                'languages_count' => $profile->languages_count,
                'educations_count' => $profile->educations_count,
                'has_cv' => $profile->documents()->where('type', 'cv')->where('scan_result', 'clean')->exists(),
                'has_verified_certificate' => $profile->documents()
                    ->whereIn('type', ['language_certificate', 'qualification'])
                    ->where('status', 'verified')
                    ->exists(),
            ])['percentage'],
        ]);
        $audit->record('candidate.profile_photo_verified', $profile, after: [
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($derivative),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $profile = CandidateProfile::query()->find($this->profileId);
        if ($profile === null || $profile->profile_photo_quarantine_path !== $this->quarantinePath) {
            return;
        }

        Storage::disk($profile->profile_photo_disk ?: 'private')->delete($this->quarantinePath);
        $profile->update([
            'profile_photo_quarantine_path' => null,
            'profile_photo_scan_result' => 'scan_failed',
            'profile_photo_scan_completed_at' => now(),
        ]);
    }
}
