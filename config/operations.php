<?php

use Monolog\Formatter\JsonFormatter;

$embeddedBuildShaPath = base_path('.erin-build-sha');
$embeddedBuildSha = is_readable($embeddedBuildShaPath)
    ? trim((string) file_get_contents($embeddedBuildShaPath))
    : null;

return [
    'build' => [
        'sha' => $embeddedBuildSha !== '' && $embeddedBuildSha !== null
            ? $embeddedBuildSha
            : env('ERIN_BUILD_SHA'),
        'image_tag' => env('ERIN_APP_TAG'),
    ],

    'network' => [
        'internal_subnet' => env('ERIN_INTERNAL_SUBNET'),
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        ))),
    ],

    'storage' => [
        'minio_app_user' => env('MINIO_APP_USER'),
    ],

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
    | A reference alone is intentionally insufficient. Every formal gate is
    | tied to a release, a real named identity and a UTC approval timestamp.
    | The readiness validator also rejects placeholders, stale records and
    | self-approval. These values are evidence pointers, never secrets.
    |
    */
    'launch_evidence' => [
        'release' => [
            'id' => env('ERIN_RELEASE_ID'),
            'commit_sha' => env('ERIN_RELEASE_COMMIT_SHA'),
            'prepared_by' => env('ERIN_RELEASE_PREPARED_BY'),
        ],
        'backup_restore' => [
            'reference' => env('ERIN_BACKUP_RESTORE_REFERENCE'),
            'verified_by' => env('ERIN_BACKUP_RESTORE_VERIFIED_BY'),
            'verified_at' => env('ERIN_BACKUP_RESTORE_VERIFIED_AT'),
            'drill_started_at' => env('ERIN_BACKUP_DRILL_STARTED_AT'),
            'drill_completed_at' => env('ERIN_BACKUP_DRILL_COMPLETED_AT'),
            'database_backup_created_at' => env('ERIN_BACKUP_DB_CREATED_AT'),
            'database_last_restored_record_at' => env('ERIN_BACKUP_DB_LAST_RESTORED_RECORD_AT'),
            'database_restored_at' => env('ERIN_BACKUP_DB_RESTORED_AT'),
            'object_storage_backup_created_at' => env('ERIN_BACKUP_OBJECT_CREATED_AT'),
            'object_storage_last_restored_record_at' => env('ERIN_BACKUP_OBJECT_LAST_RESTORED_RECORD_AT'),
            'object_storage_restored_at' => env('ERIN_BACKUP_OBJECT_RESTORED_AT'),
            'release_id' => env('ERIN_BACKUP_RESTORE_RELEASE_ID'),
            'database_rpo_target_minutes' => env('ERIN_BACKUP_DB_RPO_TARGET_MINUTES'),
            'database_rpo_achieved_minutes' => env('ERIN_BACKUP_DB_RPO_ACHIEVED_MINUTES'),
            'database_rto_target_minutes' => env('ERIN_BACKUP_DB_RTO_TARGET_MINUTES'),
            'database_rto_achieved_minutes' => env('ERIN_BACKUP_DB_RTO_ACHIEVED_MINUTES'),
            'object_storage_rpo_target_minutes' => env('ERIN_BACKUP_OBJECT_RPO_TARGET_MINUTES'),
            'object_storage_rpo_achieved_minutes' => env('ERIN_BACKUP_OBJECT_RPO_ACHIEVED_MINUTES'),
            'object_storage_rto_target_minutes' => env('ERIN_BACKUP_OBJECT_RTO_TARGET_MINUTES'),
            'object_storage_rto_achieved_minutes' => env('ERIN_BACKUP_OBJECT_RTO_ACHIEVED_MINUTES'),
            'encrypted_backup_verified' => env('ERIN_BACKUP_ENCRYPTION_VERIFIED', false),
            'isolated_restore_verified' => env('ERIN_BACKUP_ISOLATION_VERIFIED', false),
            'scope' => env('ERIN_BACKUP_SCOPE'),
            'production_gate_eligible' => env('ERIN_BACKUP_PRODUCTION_GATE_ELIGIBLE', false),
            'independently_verified' => env('ERIN_BACKUP_INDEPENDENTLY_VERIFIED', false),
        ],
        'security_review' => [
            'reference' => env('ERIN_SECURITY_REVIEW_REFERENCE'),
            'reviewed_by' => env('ERIN_SECURITY_REVIEWED_BY'),
            'reviewed_at' => env('ERIN_SECURITY_REVIEWED_AT'),
            'release_id' => env('ERIN_SECURITY_REVIEW_RELEASE_ID'),
            'commit_sha' => env('ERIN_SECURITY_REVIEW_COMMIT_SHA'),
            'automated_evidence_reference' => env('ERIN_SECURITY_AUTOMATED_EVIDENCE_REFERENCE'),
            'open_critical_findings' => env('ERIN_SECURITY_OPEN_CRITICAL_FINDINGS'),
            'open_high_findings' => env('ERIN_SECURITY_OPEN_HIGH_FINDINGS'),
            'independent_review_verified' => env('ERIN_SECURITY_INDEPENDENT_REVIEW_VERIFIED', false),
            'penetration_test_verified' => env('ERIN_SECURITY_PENETRATION_TEST_VERIFIED', false),
        ],
        'dpo_approval' => [
            'reference' => env('ERIN_DPO_APPROVAL_REFERENCE'),
            'approved_by' => env('ERIN_DPO_APPROVED_BY'),
            'approved_at' => env('ERIN_DPO_APPROVED_AT'),
            'release_id' => env('ERIN_DPO_APPROVAL_RELEASE_ID'),
            'status' => env('ERIN_DPO_APPROVAL_STATUS'),
            'authority_verified' => env('ERIN_DPO_AUTHORITY_VERIFIED', false),
        ],
        'legal_approval' => [
            'reference' => env('ERIN_LEGAL_APPROVAL_REFERENCE'),
            'approved_by' => env('ERIN_LEGAL_APPROVED_BY'),
            'approved_at' => env('ERIN_LEGAL_APPROVED_AT'),
            'release_id' => env('ERIN_LEGAL_APPROVAL_RELEASE_ID'),
            'status' => env('ERIN_LEGAL_APPROVAL_STATUS'),
            'authority_verified' => env('ERIN_LEGAL_AUTHORITY_VERIFIED', false),
        ],
        'pilot' => [
            'reference' => env('ERIN_PILOT_DECISION_REFERENCE'),
            'owner' => env('ERIN_PILOT_OWNER'),
            'deputy' => env('ERIN_PILOT_DEPUTY'),
            'decision_by' => env('ERIN_PILOT_DECISION_BY'),
            'decision_at' => env('ERIN_PILOT_DECISION_AT'),
            'started_at' => env('ERIN_PILOT_STARTED_AT'),
            'release_id' => env('ERIN_PILOT_RELEASE_ID'),
            'plan_reference' => env('ERIN_PILOT_PLAN_REFERENCE'),
            'acceptance_reference' => env('ERIN_PILOT_ACCEPTANCE_REFERENCE'),
            'rollback_reference' => env('ERIN_PILOT_ROLLBACK_REFERENCE'),
            'status' => env('ERIN_PILOT_STATUS'),
            'synthetic' => env('ERIN_PILOT_SYNTHETIC', true),
            'participant_consent_verified' => env('ERIN_PILOT_PARTICIPANT_CONSENT_VERIFIED', false),
            'stop_criteria_tested' => env('ERIN_PILOT_STOP_CRITERIA_TESTED', false),
            'rollback_tested' => env('ERIN_PILOT_ROLLBACK_TESTED', false),
        ],
    ],

    'governance_attestation' => [
        'attestation_path' => env('ERIN_GOVERNANCE_ATTESTATION_PATH'),
        'trust_root_path' => env('ERIN_GOVERNANCE_TRUST_ROOT_PATH'),
        'secret_root' => env('ERIN_GOVERNANCE_SECRET_ROOT', '/run/secrets'),
        'maximum_lifetime_hours' => (int) env(
            'ERIN_GOVERNANCE_ATTESTATION_MAX_LIFETIME_HOURS',
            168,
        ),
    ],

    'evidence_freshness' => [
        'backup_restore_days' => (int) env('ERIN_BACKUP_EVIDENCE_MAX_AGE_DAYS', 90),
        'security_review_days' => (int) env('ERIN_SECURITY_EVIDENCE_MAX_AGE_DAYS', 30),
        'dpo_approval_days' => (int) env('ERIN_DPO_EVIDENCE_MAX_AGE_DAYS', 365),
        'legal_approval_days' => (int) env('ERIN_LEGAL_EVIDENCE_MAX_AGE_DAYS', 365),
        'pilot_decision_days' => (int) env('ERIN_PILOT_EVIDENCE_MAX_AGE_DAYS', 90),
    ],

    'expected_json_log_formatter' => JsonFormatter::class,
];
