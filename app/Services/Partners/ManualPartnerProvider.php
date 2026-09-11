<?php

namespace App\Services\Partners;

use App\Contracts\PartnerProvider;
use App\Models\PartnerCase;

final class ManualPartnerProvider implements PartnerProvider
{
    public function transfer(PartnerCase $case, string $idempotencyKey): array
    {
        return ['external_reference' => 'manual-'.$case->public_id, 'status' => 'awaiting_partner'];
    }

    public function reconcile(PartnerCase $case): array
    {
        return ['status' => $case->status, 'public_status' => $case->public_status];
    }
}
