<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruiterReminderEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    /** @return BelongsTo<RecruiterReminder, $this> */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(RecruiterReminder::class, 'recruiter_reminder_id');
    }
}
