<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $first_joined_at
 * @property Carbon|null $last_joined_at
 * @property Carbon|null $last_left_at
 * @property int $total_seconds
 * @property int $join_count
 */
class InterviewAttendance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_joined_at' => 'datetime',
            'last_joined_at' => 'datetime',
            'last_left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
