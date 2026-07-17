<?php

namespace App\Models;

use App\Enums\VisaStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisaStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VisaStepStatus::class,
            'due_at' => 'date',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<VisaCase, $this>
     */
    public function visaCase(): BelongsTo
    {
        return $this->belongsTo(VisaCase::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
