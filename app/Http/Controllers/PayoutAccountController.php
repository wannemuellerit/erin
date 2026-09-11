<?php

namespace App\Http\Controllers;

use App\Models\PayoutAccount;
use App\Models\ReferralPayoutIntent;
use App\Services\Payouts\ReferralPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayoutAccountController extends Controller
{
    public function store(Request $request, ReferralPayoutService $payouts): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $data = $request->validate([
            'provider' => ['required', Rule::in(['stripe'])], 'external_account_token' => ['required', 'string', 'max:255', 'regex:/^(acct_|test_)[A-Za-z0-9_]+$/'],
            'country_code' => ['required', 'string', 'size:2'], 'currency_code' => ['required', 'string', 'size:3'],
            'terms_version' => ['required', Rule::in(['payout-v1'])], 'terms_accepted' => ['required', 'accepted'],
        ]);
        $token = $data['external_account_token'];
        $account = PayoutAccount::query()->create([
            'user_id' => $user->getKey(), 'provider' => $data['provider'], 'external_account_id' => $token,
            'external_account_hash' => hash('sha256', $data['provider'].':'.$token), 'country_code' => strtoupper($data['country_code']),
            'currency_code' => strtoupper($data['currency_code']), 'status' => 'pending',
            'kyc_status' => 'pending', 'terms_version' => $data['terms_version'],
            'terms_accepted_at' => now(),
        ]);
        if ($account->isPayable()) {
            $payouts->attachAccount($account);
        }

        return back()->with('success', __('Auszahlungskonto sicher verknüpft; Faden speichert keine Bankzugangsdaten.'));
    }

    public function destroy(Request $request, PayoutAccount $account): RedirectResponse
    {
        abort_if($request->user()?->getKey() !== $account->user_id, 403);
        abort_if(ReferralPayoutIntent::query()->where('payout_account_id', $account->getKey())->whereIn('status', ['approved', 'submitted'])->exists(), 409);
        $account->update(['disabled_at' => now(), 'status' => 'disabled']);

        return back()->with('success', __('Auszahlungskonto deaktiviert.'));
    }
}
