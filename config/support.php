<?php

return [
    'attachments' => [
        'disk' => env('SUPPORT_ATTACHMENT_DISK', 'private'),
        'max_files' => (int) env('SUPPORT_ATTACHMENT_MAX_FILES', 8),
        'max_kilobytes' => (int) env('SUPPORT_ATTACHMENT_MAX_KILOBYTES', 10240),
        'max_total_kilobytes' => (int) env('SUPPORT_ATTACHMENT_MAX_TOTAL_KILOBYTES', 15360),
        'allowed_extensions' => [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'pdf',
            'doc',
            'docx',
            'mp3',
            'm4a',
            'ogg',
            'wav',
            'webm',
        ],
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'audio/mpeg',
            'audio/mp4',
            'audio/ogg',
            'audio/wav',
            'audio/x-wav',
            'video/webm',
        ],
        'signed_url_minutes' => (int) env('SUPPORT_ATTACHMENT_URL_MINUTES', 10),
        'orphan_grace_hours' => (int) env('SUPPORT_ATTACHMENT_ORPHAN_GRACE_HOURS', 24),
    ],
    'sync' => [
        'lock_seconds' => (int) env('SUPPORT_SYNC_LOCK_SECONDS', 300),
        'lock_retry_seconds' => (int) env('SUPPORT_SYNC_LOCK_RETRY_SECONDS', 5),
    ],
];
