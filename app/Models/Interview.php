<?php

namespace App\Models;

use App\Enums\InterviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property int $organizer_id
 * @property int $proposed_by
 * @property InterviewStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string $timezone
 * @property string|null $livekit_room_name
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $metadata
 */
class Interview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => InterviewStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * @return HasMany<InterviewProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(InterviewProposal::class);
    }
}
