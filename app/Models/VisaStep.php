<?php

namespace App\Models;

use App\Enums\VisaStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $visa_case_id
 * @property int|null $responsible_user_id
 * @property VisaStepStatus $status
 * @property string $title
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property-read VisaCase $visaCase
 */
class VisaStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'visa_case_id' => 'integer',
            'responsible_user_id' => 'integer',
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

    /** @return HasMany<VisaTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(VisaTask::class);
    }
}
