<?php

namespace App\Services\Ticketing;

use App\Jobs\ScanSupportTicketAttachment;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Documents\UploadPolicy;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SupportAttachmentManager
{
    public function __construct(
        private readonly SupportAttachmentLimits $limits,
        private readonly UploadPolicy $uploads,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(string $bodyField): array
    {
        $maxFiles = (int) config('support.attachments.max_files', 8);
        $maxKilobytes = app(UploadPolicy::class)->maxFileKilobytes(
            (int) config('support.attachments.max_kilobytes', 10240),
        );
        $maxTotalKilobytes = (int) config(
            'support.attachments.max_total_kilobytes',
            15360,
        );
        $extensions = implode(',', config('support.attachments.allowed_extensions', []));

        return [
            $bodyField => [
                'nullable',
                'string',
                'max:20000',
                'required_without:attachments',
            ],
            'attachments' => [
                'array',
                "max:{$maxFiles}",
                "required_without:{$bodyField}",
                static function (string $attribute, mixed $value, Closure $fail) use (
                    $maxTotalKilobytes,
                ): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $totalBytes = array_reduce(
                        $value,
                        static fn (int $total, mixed $file): int => $total + (
                            $file instanceof UploadedFile
                                ? max(0, (int) $file->getSize())
                                : 0
                        ),
                        0,
                    );
                    if ($totalBytes <= $maxTotalKilobytes * 1024) {
                        return;
                    }

                    $fail(trans('validation.support_attachments_total', [
                        'size' => $maxTotalKilobytes / 1024,
                    ]));
                },
            ],
            'attachments.*' => [
                'file',
                "mimes:{$extensions}",
                "max:{$maxKilobytes}",
            ],
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function storeUploads(
        SupportTicketMessage $message,
        array $files,
        ?int $uploaderId,
    ): void {
        if ($files === []) {
            return;
        }

        $this->limits->assertUploads($files);
        if ($uploaderId !== null && ($uploader = User::query()->find($uploaderId)) !== null) {
            $this->uploads->assertCanStore($uploader, $files, 'attachments');
        }
        $disk = (string) config('support.attachments.disk', 'private');
        $storedPaths = [];
        $attachments = [];

        try {
            foreach ($files as $file) {
                $path = $file->store(
                    "support-tickets/{$message->support_ticket_id}/erin",
                    $disk,
                );
                if ($path === false) {
                    throw new RuntimeException('Der Supportanhang konnte nicht privat gespeichert werden.');
                }

                $storedPaths[] = $path;
                $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $attachment = $message->files()->create([
                    'uploaded_by' => $uploaderId,
                    'source' => 'erin',
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => mb_substr($originalName, 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'checksum_sha256' => hash_file('sha256', $file->getRealPath()) ?: null,
                    'scan_result' => 'pending',
                ]);
                $attachments[] = $attachment;
            }
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk($disk)->delete($path);
            }
            SupportTicketAttachment::query()
                ->whereKey(array_map(
                    static fn (SupportTicketAttachment $attachment): int => $attachment->getKey(),
                    $attachments,
                ))
                ->delete();

            throw $exception;
        }

        foreach ($attachments as $attachment) {
            ScanSupportTicketAttachment::dispatch($attachment->getKey())->afterCommit();
        }
    }
}
