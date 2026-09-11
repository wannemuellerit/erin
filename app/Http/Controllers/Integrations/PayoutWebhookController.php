<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\PayoutAccount;
use App\Models\PayoutWebhookReceipt;
use App\Models\ReferralPayoutIntent;
use App\Services\Payouts\ReferralPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayoutWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ReferralPayoutService $payouts): JsonResponse
    {
        abort_unless(in_array($provider, ['stripe'], true), 404);
        $secret = (string) config('services.payouts.webhook_secret');
        abort_if(strlen($secret) < 32, 503);
        $body = $request->getContent();
        abort_if(strlen($body) > (int) config('services.payouts.webhook_max_bytes', 262144), 413);
        abort_unless(hash_equals(hash_hmac('sha256', $body, $secret), (string) $request->header('X-Erin-Signature')), 401);
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::in(['account.verified', 'payout.paid', 'payout.failed'])],
            'reference' => ['required', 'string', 'max:255'], 'failure_code' => ['nullable', 'string', 'max:80'],
        ]);
        $duplicate = false;
        DB::transaction(function () use ($provider, $data, $body, $payouts, &$duplicate): void {
            $receipt = PayoutWebhookReceipt::query()->firstOrCreate(
                ['provider' => $provider, 'event_id' => $data['event_id']],
                ['payload_hash' => hash('sha256', $body), 'signature_valid' => true, 'event_type' => $data['type']],
            );
            if (! $receipt->wasRecentlyCreated) {
                abort_unless(hash_equals($receipt->payload_hash, hash('sha256', $body)), 409);
                $duplicate = true;

                return;
            }
            if ($data['type'] === 'account.verified') {
                $account = PayoutAccount::query()->where('provider', $provider)
                    ->where('external_account_hash', hash('sha256', $provider.':'.$data['reference']))->lockForUpdate()->firstOrFail();
                $account->update(['status' => 'active', 'kyc_status' => 'verified', 'verified_at' => now()]);
                $payouts->attachAccount($account);
            } else {
                /** @var ReferralPayoutIntent $intent */
                $intent = ReferralPayoutIntent::query()->with('referral')->where('provider_reference', $data['reference'])->lockForUpdate()->firstOrFail();
                $receipt->update(['referral_payout_intent_id' => $intent->getKey()]);
                if ($data['type'] === 'payout.paid') {
                    $intent->update(['status' => 'paid', 'paid_at' => now(), 'failure_code' => null]);
                    $intent->referral->update(['status' => 'paid', 'paid_at' => now()]);
                } else {
                    $intent->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => $data['failure_code'] ?? 'provider_failed']);
                }
            }
            $receipt->update(['processed_at' => now()]);
        }, 3);

        return response()->json(['accepted' => true, 'duplicate' => $duplicate]);
    }
}
