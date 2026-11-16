<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisaCaseEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<VisaCase, $this> */
    public function visaCase(): BelongsTo
    {
        return $this->belongsTo(VisaCase::class);
    }
}
