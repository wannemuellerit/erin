<?php

namespace App\Services\Operations;

final class SyntheticPilotDrill
{
    /**
     * @return array<string, mixed>
     */
    public function run(string $scenario, string $startedAt, string $completedAt): array
    {
        $input = $this->baseline();
        $input = $this->applyScenario($input, $scenario);
        $checks = $this->checks($input);
        $stopTriggered = collect($checks)->contains(
            static fn (array $check): bool => $check['kind'] === 'stop' && $check['status'] === 'fail',
        );
        $failed = collect($checks)->contains(
            static fn (array $check): bool => $check['status'] === 'fail',
        );

        return [
            'schema_version' => 1,
            'evidence_type' => 'synthetic_local_pilot_control_model',
            'classification' => 'LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE',
            'production_gate_eligible' => false,
            'synthetic' => true,
            'execution_mode' => 'rule_model_only',
            'scenario' => $scenario,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'roles' => $input['roles'],
            'participants' => $input['participants'],
            'metrics' => $input['metrics'],
            'model_inputs' => $input['model_inputs'],
            'checks' => $checks,
            'result' => [
                'status' => $stopTriggered ? 'stopped' : ($failed ? 'failed' : 'passed'),
                'stop_triggered' => $stopTriggered,
                'failed_checks' => collect($checks)
                    ->where('status', 'fail')
                    ->pluck('id')
                    ->values()
                    ->all(),
            ],
            'external_gates' => [
                'real_participant_consent' => 'open',
                'independent_go_no_go' => 'open',
                'dpo_approval' => 'open',
                'legal_approval' => 'open',
                'security_approval' => 'open',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function scenarios(): array
    {
        return [
            'pass',
            'tenant-leak',
            'document-leak',
            'ai-autonomy',
            'webhook-duplication',
            'support-desync',
            'role-collision',
            'participant-overflow',
            'company-overflow',
            'rollback-failure',
            'maintenance-mode-failure',
            'monitoring-gap',
            'audit-log-gap',
            'external-notification-leak',
            'interview-authorization-gap',
            'critical-incident',
            'timeline-inconsistency',
            'protected-match-factor',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseline(): array
    {
        return [
            'roles' => [
                'owner' => 'Pilot Owner Demo <pilot-owner.demo@wannemueller.dev>',
                'deputy' => 'Pilot Deputy Demo <pilot-deputy.demo@wannemueller.dev>',
                'decision_by' => 'Pilot Decision Demo <pilot-decision.demo@wannemueller.dev>',
                'release_prepared_by' => 'Release Preparation Demo <release.demo@wannemueller.dev>',
            ],
            'participants' => [
                'companies' => 2,
                'candidates' => 10,
                'company_limit' => 2,
                'candidate_limit' => 10,
            ],
            'metrics' => [
                'tenant_data_leaks' => 0,
                'unauthorized_document_downloads' => 0,
                'ai_automatic_status_changes' => 0,
                'webhook_idempotency_percent' => 100,
                'support_consistency_percent' => 100,
                'interview_authorization_percent' => 100,
                'open_critical_incidents' => 0,
                'open_high_incidents' => 0,
                'timeline_inconsistencies' => 0,
                'protected_match_factors' => 0,
            ],
            'model_inputs' => [
                'rollback_succeeds' => true,
                'maintenance_mode_succeeds' => true,
                'monitoring_is_available' => true,
                'audit_log_is_available' => true,
                'external_notifications_are_disabled' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function applyScenario(array $input, string $scenario): array
    {
        match ($scenario) {
            'pass' => null,
            'tenant-leak' => $input['metrics']['tenant_data_leaks'] = 1,
            'document-leak' => $input['metrics']['unauthorized_document_downloads'] = 1,
            'ai-autonomy' => $input['metrics']['ai_automatic_status_changes'] = 1,
            'webhook-duplication' => $input['metrics']['webhook_idempotency_percent'] = 99,
            'support-desync' => $input['metrics']['support_consistency_percent'] = 90,
            'role-collision' => $input['roles']['decision_by'] = $input['roles']['owner'],
            'participant-overflow' => $input['participants']['candidates'] = 11,
            'company-overflow' => $input['participants']['companies'] = 3,
            'rollback-failure' => $input['model_inputs']['rollback_succeeds'] = false,
            'maintenance-mode-failure' => $input['model_inputs']['maintenance_mode_succeeds'] = false,
            'monitoring-gap' => $input['model_inputs']['monitoring_is_available'] = false,
            'audit-log-gap' => $input['model_inputs']['audit_log_is_available'] = false,
            'external-notification-leak' => $input['model_inputs']['external_notifications_are_disabled'] = false,
            'interview-authorization-gap' => $input['metrics']['interview_authorization_percent'] = 99,
            'critical-incident' => $input['metrics']['open_critical_incidents'] = 1,
            'timeline-inconsistency' => $input['metrics']['timeline_inconsistencies'] = 1,
            'protected-match-factor' => $input['metrics']['protected_match_factors'] = 1,
            default => throw new \InvalidArgumentException('Unbekanntes Pilot-Drill-Szenario.'),
        };

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: string, kind: 'acceptance'|'stop', status: 'pass'|'fail'}>
     */
    private function checks(array $input): array
    {
        $roles = array_values($input['roles']);
        $participants = $input['participants'];
        $metrics = $input['metrics'];
        $modelInputs = $input['model_inputs'];

        return [
            $this->check('roles.separated', count($roles) === count(array_unique($roles))),
            $this->check(
                'participants.within_limits',
                $participants['companies'] <= $participants['company_limit']
                    && $participants['candidates'] <= $participants['candidate_limit'],
            ),
            $this->check('rollback.modeled_success', $modelInputs['rollback_succeeds'] === true),
            $this->check(
                'maintenance_mode.modeled_success',
                $modelInputs['maintenance_mode_succeeds'] === true,
            ),
            $this->check(
                'monitoring.modeled_available',
                $modelInputs['monitoring_is_available'] === true,
                'stop',
            ),
            $this->check(
                'audit_log.modeled_available',
                $modelInputs['audit_log_is_available'] === true,
                'stop',
            ),
            $this->check(
                'external_notifications.modeled_disabled',
                $modelInputs['external_notifications_are_disabled'] === true,
            ),
            $this->check('tenant_data_leak.none', $metrics['tenant_data_leaks'] === 0, 'stop'),
            $this->check(
                'unauthorized_document_download.none',
                $metrics['unauthorized_document_downloads'] === 0,
                'stop',
            ),
            $this->check(
                'ai_automatic_status_change.none',
                $metrics['ai_automatic_status_changes'] === 0,
                'stop',
            ),
            $this->check(
                'webhook.idempotency',
                $metrics['webhook_idempotency_percent'] === 100,
                'stop',
            ),
            $this->check('support.consistency', $metrics['support_consistency_percent'] === 100),
            $this->check(
                'interview.authorization',
                $metrics['interview_authorization_percent'] === 100,
            ),
            $this->check(
                'incidents.none_high_or_critical',
                $metrics['open_critical_incidents'] === 0
                    && $metrics['open_high_incidents'] === 0,
                'stop',
            ),
            $this->check('timelines.consistent', $metrics['timeline_inconsistencies'] === 0),
            $this->check('matching.no_protected_factors', $metrics['protected_match_factors'] === 0),
        ];
    }

    /**
     * @param  'acceptance'|'stop'  $kind
     * @return array{id: string, kind: 'acceptance'|'stop', status: 'pass'|'fail'}
     */
    private function check(string $id, bool $passes, string $kind = 'acceptance'): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'status' => $passes ? 'pass' : 'fail',
        ];
    }
}
