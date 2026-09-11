<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\PartnerCase;
use App\Models\PartnerIntegrationReceipt;
use App\Models\PartnerOrganization;
use App\Services\Partners\PartnerCaseWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerWebhookController extends Controller
{
    public function __invoke(Request $request, PartnerOrganization $organization, PartnerCaseWorkflow $workflow): JsonResponse
    {
        abort_unless($organization->isOperational(), 404);
        $secret = (string) config('services.partners.webhook_secret');
        abort_if(strlen($secret) < 32, 503);
        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('services.partners.webhook_max_bytes', 262144), 413);
        abort_unless(hash_equals(hash_hmac('sha256', $body, $secret), (string) $request->header('X-Erin-Signature')), 401);
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:160'], 'case_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['in_progress', 'waiting_for_candidate', 'submitted_to_authority', 'booked', 'completed', 'rejected'])],
            'public_status' => ['required', Rule::in(['in_progress', 'action_required', 'submitted', 'booked', 'completed', 'rejected'])],
            'summary' => ['required', 'string', 'max:300'],
        ]);

        $duplicate = false;
        DB::transaction(function () use ($organization, $data, $body, $workflow, &$duplicate): void {
            $receipt = PartnerIntegrationReceipt::query()->firstOrCreate(
                ['partner_organization_id' => $organization->getKey(), 'provider' => $organization->integration_mode, 'idempotency_key' => $data['event_id']],
                ['direction' => 'inbound', 'payload_hash' => hash('sha256', $body), 'signature_valid' => true, 'status' => 'processing'],
            );
            if (! $receipt->wasRecentlyCreated) {
                abort_unless(hash_equals($receipt->payload_hash, hash('sha256', $body)), 409);
                $duplicate = true;

                return;
            }
            $case = PartnerCase::query()->where('partner_organization_id', $organization->getKey())->where('public_id', $data['case_id'])->lockForUpdate()->firstOrFail();
            abort_unless($case->hasActiveConsent(), 410);
            $case->update(['status' => $data['status'], 'public_status' => $data['public_status']]);
            $workflow->record($case, 'provider_status', $data['summary'], null, true);
            $receipt->update(['partner_case_id' => $case->getKey(), 'status' => 'processed', 'processed_at' => now()]);
        });

        return response()->json(['accepted' => true, 'duplicate' => $duplicate]);
    }
}
