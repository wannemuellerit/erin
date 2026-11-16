<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int|null $application_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Company|null $company
 */
class DocumentAccessGrant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CandidateDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CandidateDocument::class, 'candidate_document_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<JobApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }
}
