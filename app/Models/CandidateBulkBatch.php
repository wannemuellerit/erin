<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $created_by
 * @property string $action
 * @property string $status
 * @property array<string, mixed>|null $filter_snapshot
 * @property array<string, mixed> $payload
 * @property string $request_hash
 * @property int $total
 * @property int $processed
 * @property int $succeeded
 * @property int $failed
 * @property-read Company $company
 * @property-read User $creator
 */
class CandidateBulkBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filter_snapshot' => 'array',
            'payload' => 'array',
            'cancellation_requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CandidateBulkBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CandidateBulkBatchItem::class);
    }
}
