<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCaseArtifact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['encrypted_payload' => 'encrypted:array', 'valid_until' => 'datetime'];
    }

    /** @return BelongsTo<CandidateDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(CandidateDocument::class, 'source_document_id');
    }
}
