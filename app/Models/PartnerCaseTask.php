<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCaseTask extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<PartnerCase, $this> */
    public function partnerCase(): BelongsTo
    {
        return $this->belongsTo(PartnerCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
