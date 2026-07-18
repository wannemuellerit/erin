<?php

use Monolog\Formatter\JsonFormatter;

return [
    'queue' => [
        'queues' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ERIN_QUEUE_HEALTH_QUEUES', 'high,default,low')),
        ))),
        'max_pending' => (int) env('ERIN_QUEUE_MAX_PENDING', 500),
        'max_failed' => (int) env('ERIN_QUEUE_MAX_FAILED', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Zero disables a rule. Production values must only be enabled after the
    | retention policy has been approved by the responsible DPO/legal owner.
    |
    */
    'retention' => [
        'login_history_days' => (int) env('ERIN_RETENTION_LOGIN_HISTORY_DAYS', 0),
        'read_notification_days' => (int) env('ERIN_RETENTION_READ_NOTIFICATION_DAYS', 0),
        'activity_days' => (int) env('ERIN_RETENTION_ACTIVITY_DAYS', 0),
        'candidate_import_days' => (int) env('ERIN_RETENTION_CANDIDATE_IMPORT_DAYS', 0),
        'failed_job_days' => (int) env('ERIN_RETENTION_FAILED_JOB_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence-backed launch gates
    |--------------------------------------------------------------------------
    |
    | References are deliberately empty by default. They identify external
    | evidence and approvals; setting them does not replace the corresponding
    | review.
    |
    */
    'gates' => [
        'backup_restore_verified_at' => env('ERIN_BACKUP_RESTORE_VERIFIED_AT'),
        'security_review_reference' => env('ERIN_SECURITY_REVIEW_REFERENCE'),
        'dpo_approval_reference' => env('ERIN_DPO_APPROVAL_REFERENCE'),
        'legal_approval_reference' => env('ERIN_LEGAL_APPROVAL_REFERENCE'),
        'pilot_owner' => env('ERIN_PILOT_OWNER'),
    ],

    'expected_json_log_formatter' => JsonFormatter::class,
];
