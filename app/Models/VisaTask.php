<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $visa_step_id
 * @property int|null $assigned_to
 * @property string $title
 * @property string $status
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_reminded_at
 * @property-read VisaStep $step
 * @property-read User|null $assignee
 */
class VisaTask extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'completed_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VisaStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(VisaStep::class, 'visa_step_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
