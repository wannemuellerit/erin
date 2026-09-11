<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at @property \Illuminate\Support\Carbon|null $revoked_at */
class PartnerDocumentGrant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_downloaded_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
