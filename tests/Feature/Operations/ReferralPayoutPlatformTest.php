<?php

use App\Contracts\PayoutProvider;
use App\Enums\ReferralStatus;
use App\Jobs\ProcessReferralPayout;
use App\Models\PayoutAccount;
use App\Models\PayoutWebhookReceipt;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\ReferralPayoutIntent;
use App\Models\User;
use App\Services\Payouts\ReferralPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ErinPayoutProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.payouts.webhook_secret', str_repeat('p', 32));
    config()->set('services.payouts.fraud_review_score', 50);
    config()->set('services.payouts.manual_review_amount_cents', 50000);
    app()->instance(PayoutProvider::class, new ErinPayoutProvider);
});

function erinPayoutReferral(User $referrer, array $attributes = []): Referral
{
    $code = ReferralCode::query()->create(['user_id' => $referrer->getKey(), 'code' => 'code-'.$referrer->getKey().'-'.uniqid(), 'commission_cents' => 10000, 'currency' => 'EUR']);

    return Referral::query()->create([
        'referral_code_id' => $code->getKey(), 'status' => ReferralStatus::Approved, 'clicked_at' => now()->subDays(40),
        'hired_at' => now()->subDays(35), 'hold_until' => now()->subDays(5), 'approved_at' => now(),
        'commission_cents' => 10000, 'currency' => 'EUR', ...$attributes,
    ]);
}

function erinPayoutAccount(User $user, array $attributes = []): PayoutAccount
{
    return PayoutAccount::query()->create([
        'user_id' => $user->getKey(), 'provider' => 'stripe', 'external_account_id' => 'acct_'.$user->getKey(),
        'external_account_hash' => hash('sha256', 'stripe:acct_'.$user->getKey()), 'country_code' => 'DE', 'currency_code' => 'EUR',
        'status' => 'active', 'kyc_status' => 'verified', 'terms_version' => 'payout-v1', 'terms_accepted_at' => now(), 'verified_at' => now(),
        ...$attributes,
    ]);
}

it('stores only an encrypted provider identifier and never bank credentials', function () {
    $user = User::factory()->create();
    $account = erinPayoutAccount($user);
    $raw = DB::table('payout_accounts')->where('id', $account->getKey())->value('external_account_id');

    expect($raw)->not->toBe('acct_'.$user->getKey());
    expect($account->fresh()->external_account_id)->toBe('acct_'.$user->getKey());
    expect(Schema::hasColumn('payout_accounts', 'iban'))->toBeFalse();
});

it('creates one intent after hold and submits it exactly once with a stable idempotency key', function () {
    Queue::fake();
    $referrer = User::factory()->create();
    $admin = User::factory()->create();
    erinPayoutAccount($referrer);
    $referral = erinPayoutReferral($referrer);
    $service = app(ReferralPayoutService::class);
    $intent = $service->createForApprovedReferral($referral, $admin);
    $same = $service->createForApprovedReferral($referral, $admin);

    expect($same->getKey())->toBe($intent->getKey());
    expect(ReferralPayoutIntent::query()->count())->toBe(1);
    Queue::assertPushed(ProcessReferralPayout::class, 1);

    $job = new ProcessReferralPayout($intent->getKey());
    $job->handle(app(PayoutProvider::class));
    $job->handle(app(PayoutProvider::class));
    expect(app(PayoutProvider::class)->submissions)->toHaveCount(1);
    expect($intent->fresh())->status->toBe('submitted')->provider_reference->not->toBeNull();
});

it('keeps suspicious payouts in manual review and blocks pre-hold payouts', function () {
    Queue::fake();
    $referrer = User::factory()->create();
    $account = erinPayoutAccount($referrer);
    $referral = erinPayoutReferral($referrer, ['referred_user_id' => $referrer->getKey()]);
    $intent = app(ReferralPayoutService::class)->createForApprovedReferral($referral, User::factory()->create());
    expect($intent)->status->toBe('manual_review')->fraud_score->toBe(100);
    expect($intent->fraud_signals)->toContain('self_referral');
    Queue::assertNothingPushed();

    $tooEarly = erinPayoutReferral(User::factory()->create(), ['hold_until' => now()->addDay()]);
    expect(fn () => app(ReferralPayoutService::class)->createForApprovedReferral($tooEarly, User::factory()->create()))->toThrow(HttpException::class);
});

it('records generic retry state on provider failure without persisting the provider message', function () {
    Queue::fake();
    $referrer = User::factory()->create();
    erinPayoutAccount($referrer);
    $intent = app(ReferralPayoutService::class)->createForApprovedReferral(erinPayoutReferral($referrer), User::factory()->create());
    app(PayoutProvider::class)->fail = true;

    expect(fn () => (new ProcessReferralPayout($intent->getKey()))->handle(app(PayoutProvider::class)))->toThrow(RuntimeException::class);
    expect($intent->fresh())->status->toBe('retrying')->failure_code->toBe('RuntimeException');
    expect(DB::table('referral_payout_intents')->where('id', $intent->getKey())->value('failure_code'))->not->toContain('secret');
});

it('marks referrals paid only from a signed and idempotent provider callback', function () {
    Queue::fake();
    $referrer = User::factory()->create();
    erinPayoutAccount($referrer);
    $referral = erinPayoutReferral($referrer);
    $intent = app(ReferralPayoutService::class)->createForApprovedReferral($referral, User::factory()->create());
    $intent->update(['status' => 'submitted', 'provider_reference' => 'tr_123', 'submitted_at' => now()]);
    $payload = json_encode(['event_id' => 'evt_1', 'type' => 'payout.paid', 'reference' => 'tr_123'], JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ERIN_SIGNATURE' => hash_hmac('sha256', $payload, str_repeat('p', 32))];

    $this->call('POST', '/integrations/payouts/stripe/webhook', [], [], [], $server, $payload)->assertOk()->assertJsonPath('duplicate', false);
    $this->call('POST', '/integrations/payouts/stripe/webhook', [], [], [], $server, $payload)->assertOk()->assertJsonPath('duplicate', true);
    expect($intent->fresh())->status->toBe('paid');
    expect($referral->fresh())->status->toBe(ReferralStatus::Paid);
    expect(PayoutWebhookReceipt::query()->count())->toBe(1);
});

it('rejects weak, oversized and conflicting payout callbacks without changing state', function () {
    $referrer = User::factory()->create();
    erinPayoutAccount($referrer);
    $referral = erinPayoutReferral($referrer);
    $intent = app(ReferralPayoutService::class)->createForApprovedReferral($referral, User::factory()->create());
    $intent->update(['status' => 'submitted', 'provider_reference' => 'tr_conflict', 'submitted_at' => now()]);
    $send = function (array $data, string $secret = 'pppppppppppppppppppppppppppppppp') {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/integrations/payouts/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERIN_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ], $body);
    };
    $payload = ['event_id' => 'evt_conflict', 'type' => 'payout.paid', 'reference' => 'tr_conflict'];

    config()->set('services.payouts.webhook_secret', 'weak');
    $send($payload, 'weak')->assertServiceUnavailable();

    config()->set('services.payouts.webhook_secret', str_repeat('p', 32));
    config()->set('services.payouts.webhook_max_bytes', 8);
    $send($payload)->assertStatus(413);

    config()->set('services.payouts.webhook_max_bytes', 4096);
    $send($payload)->assertOk();
    $send([...$payload, 'type' => 'payout.failed', 'failure_code' => 'forged'])
        ->assertConflict();

    expect($intent->fresh()->status)->toBe('paid')
        ->and(PayoutWebhookReceipt::query()->count())->toBe(1);
});
