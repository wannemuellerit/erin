<?php

namespace App\Console\Commands;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Notifications\ActivityNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NotifyEligibleReferralPayouts extends Command
{
    protected $signature = 'erin:referrals:notify-eligible {--limit=250}';

    protected $description = 'Notify referrers when the 30-day hold period has ended';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $sent = 0;

        Referral::query()
            ->where('status', ReferralStatus::Holding)
            ->whereNotNull('hold_until')
            ->where('hold_until', '<=', now())
            ->whereNull('approval_notified_at')
            ->orderBy('hold_until')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$sent): void {
                DB::transaction(function () use ($id, &$sent): void {
                    $referral = Referral::query()
                        ->with('referralCode.user')
                        ->lockForUpdate()
                        ->find($id);
                    $rawStatus = $referral?->getAttribute('status');
                    $status = $rawStatus instanceof ReferralStatus
                        ? $rawStatus
                        : ReferralStatus::tryFrom((string) $rawStatus);
                    $rawHoldUntil = $referral?->getAttribute('hold_until');
                    $holdUntil = $rawHoldUntil instanceof \DateTimeInterface
                        ? Carbon::instance($rawHoldUntil)
                        : (is_string($rawHoldUntil) ? Carbon::parse($rawHoldUntil) : null);

                    if (
                        $referral === null
                        || $status !== ReferralStatus::Holding
                        || $holdUntil === null
                        || $holdUntil->isFuture()
                        || $referral->getAttribute('approval_notified_at') !== null
                    ) {
                        return;
                    }

                    $referral->referralCode->user->notify(new ActivityNotification([
                        'event' => 'referral.approval_eligible',
                        'translations' => [
                            'de' => [
                                'title' => 'Referral-Provision ist freigabefähig',
                                'message' => 'Die 30-Tage-Haltefrist ist abgelaufen. Die Provision wird nun geprüft.',
                            ],
                            'en' => [
                                'title' => 'Referral commission is eligible',
                                'message' => 'The 30-day holding period has ended. The commission is now under review.',
                            ],
                        ],
                        'url' => route('referrals.index'),
                        'referral_id' => $referral->getKey(),
                    ]));
                    $referral->update(['approval_notified_at' => now()]);
                    $sent++;
                }, 3);
            });

        $this->info("{$sent} Referral-Benachrichtigung(en) versendet.");

        return self::SUCCESS;
    }
}
