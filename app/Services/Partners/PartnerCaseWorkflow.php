<?php

namespace App\Services\Partners;

use App\Contracts\PartnerProvider;
use App\Models\PartnerCase;
use App\Models\PartnerCaseEvent;
use App\Models\PartnerCaseTask;
use App\Models\PartnerIntegrationReceipt;
use App\Models\User;
use App\Models\VisaCase;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\DB;

final class PartnerCaseWorkflow
{
    public function __construct(private readonly PartnerProvider $provider) {}

    public function record(PartnerCase $case, string $type, string $summary, ?User $actor = null, bool $companyVisible = false): void
    {
        PartnerCaseEvent::query()->create([
            'partner_case_id' => $case->getKey(), 'actor_user_id' => $actor?->getKey(),
            'event_type' => $type, 'summary' => $summary, 'metadata' => [],
            'visible_to_candidate' => true, 'visible_to_company' => $companyVisible,
        ]);

        if (in_array($case->service_type->value, ['translation', 'recognition'], true)) {
            $visaCase = VisaCase::query()
                ->whereHas('candidateProfile', fn ($query) => $query->where('user_id', $case->candidate_user_id))
                ->latest('id')
                ->first();
            $visaCase?->events()->create([
                'actor_id' => $actor?->getKey(),
                'event' => 'partner_service.'.$type,
                'visibility' => 'shared',
                'payload' => [
                    'partner_case_id' => $case->public_id,
                    'service_type' => $case->service_type->value,
                    'public_status' => $case->public_status,
                ],
            ]);
        }
    }

    public function requestCandidateAction(PartnerCase $case, string $type, string $summary, User $actor): PartnerCaseTask
    {
        $key = hash('sha256', implode(':', [
            'partner-action',
            $case->public_id,
            $type,
            $summary,
        ]));
        $task = PartnerCaseTask::query()->firstOrCreate([
            'idempotency_key' => $key,
        ], [
            'partner_case_id' => $case->getKey(),
            'assigned_to' => $case->candidate_user_id,
            'created_by' => $actor->getKey(),
            'type' => $type,
            'title' => $summary,
            'status' => 'open',
            'due_at' => now()->addDays(7),
        ]);

        if ($task->wasRecentlyCreated) {
            $case->candidate->notify(new ActivityNotification([
                'event' => 'partner.action_required',
                'title_de' => 'Rückmeldung zu deinem Servicefall erforderlich',
                'title_en' => 'Your service case needs a response',
                'message_de' => $summary,
                'message_en' => $summary,
                'url' => route('candidate.services.index'),
            ]));
        }

        return $task;
    }

    public function transfer(PartnerCase $case, ?User $actor = null): PartnerCase
    {
        abort_unless($case->hasActiveConsent(), 422, __('Eine aktive, zweckgebundene Einwilligung ist erforderlich.'));
        abort_if($case->organization === null || ! $case->organization->isOperational(), 422, __('Der Partner ist nicht freigegeben.'));
        $key = hash('sha256', 'partner-case-transfer:'.$case->public_id.':'.$case->updated_at?->timestamp);

        return DB::transaction(function () use ($case, $actor, $key): PartnerCase {
            $receipt = PartnerIntegrationReceipt::query()->firstOrCreate(
                ['partner_organization_id' => $case->partner_organization_id, 'provider' => $case->organization->integration_mode, 'idempotency_key' => $key],
                ['partner_case_id' => $case->getKey(), 'direction' => 'outbound', 'payload_hash' => hash('sha256', $case->public_id), 'signature_valid' => true, 'status' => 'processing'],
            );
            if ($receipt->processed_at !== null) {
                return $case->refresh();
            }
            $result = $this->provider->transfer($case, $key);
            $case->forceFill(['external_reference' => $result['external_reference'], 'status' => $result['status'], 'public_status' => 'submitted'])->save();
            $receipt->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
            $this->record($case, 'transferred', __('An den ausgewählten Partner übermittelt.'), $actor, true);

            return $case->refresh();
        });
    }

    public function withdraw(PartnerCase $case, User $actor): PartnerCase
    {
        $case->forceFill(['withdrawn_at' => now(), 'status' => 'withdrawn', 'public_status' => 'withdrawn', 'external_reference' => null])->save();
        $case->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->record($case, 'consent_withdrawn', __('Einwilligung widerrufen; alle Freigaben wurden beendet.'), $actor, true);

        return $case;
    }
}
