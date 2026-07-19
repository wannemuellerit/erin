<?php

namespace App\Observers;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use BackedEnum;
use Illuminate\Support\Facades\Auth;

class ReferralObserver
{
    public function created(Referral $referral): void
    {
        $this->record($referral, null, $referral->getAttribute('status'));
    }

    public function updated(Referral $referral): void
    {
        if (! $referral->wasChanged('status')) {
            return;
        }

        $this->record(
            $referral,
            $referral->getRawOriginal('status'),
            $referral->getAttribute('status'),
        );
    }

    private function record(Referral $referral, mixed $from, mixed $to): void
    {
        $toValue = $this->value($to);

        $referral->statusHistory()->create([
            'changed_by' => Auth::id(),
            'from_status' => $from === null ? null : $this->value($from),
            'to_status' => $toValue,
            'reason' => 'lifecycle.'.$toValue,
        ]);
    }

    private function value(mixed $value): string
    {
        if ($value instanceof ReferralStatus || $value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
