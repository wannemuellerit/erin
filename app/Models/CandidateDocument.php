<?php

namespace App\Models;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property CandidateDocumentType $type
 * @property CandidateDocumentStatus $status
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $sha256
 * @property string|null $scan_result
 * @property Carbon|null $expires_at
 */
class CandidateDocument extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CandidateDocumentType::class,
            'status' => CandidateDocumentStatus::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'scan_completed_at' => 'datetime',
            'shared_with_employers' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isAvailableForSharing(): bool
    {
        return $this->status === CandidateDocumentStatus::Verified
            && $this->shared_with_employers
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
