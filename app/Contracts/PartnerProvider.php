<?php

namespace App\Contracts;

use App\Models\PartnerCase;

interface PartnerProvider
{
    /** @return array{external_reference: string, status: string} */
    public function transfer(PartnerCase $case, string $idempotencyKey): array;

    /** @return array{status: string, public_status: string} */
    public function reconcile(PartnerCase $case): array;
}
