<?php

namespace Tests\Support;

use App\Contracts\PartnerProvider;
use App\Models\PartnerCase;

final class ErinPartnerProvider implements PartnerProvider
{
    /** @var list<string> */
    public array $transfers = [];

    public function transfer(PartnerCase $case, string $idempotencyKey): array
    {
        $this->transfers[] = $idempotencyKey;

        return ['external_reference' => 'external-'.$case->public_id, 'status' => 'in_progress'];
    }

    public function reconcile(PartnerCase $case): array
    {
        return ['status' => $case->status, 'public_status' => $case->public_status];
    }
}
