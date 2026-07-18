<?php

namespace App\Models;

use App\Enums\GdprRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $handled_by
 * @property int|null $verified_by
 * @property int|null $approved_by
 * @property string $type
 * @property GdprRequestStatus $status
 * @property bool $legal_hold
 * @property string|null $legal_hold_reason
 * @property string|null $export_disk
 * @property string|null $export_path
 * @property Carbon|null $verified_at
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $export_expires_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $completed_at
 * @property-read User $user
 */
class GdprRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GdprRequestStatus::class,
            'legal_hold' => 'boolean',
            'verified_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'failed_at' => 'datetime',
            'export_expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'result_summary' => 'array',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
