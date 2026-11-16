<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateBulkBatchItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['candidate_updated_at' => 'datetime'];
    }

    /** @return BelongsTo<CandidateBulkBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CandidateBulkBatch::class, 'candidate_bulk_batch_id');
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class, 'candidate_profile_id');
    }
}
