<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisaCaseDocument extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<VisaCase, $this> */
    public function visaCase(): BelongsTo
    {
        return $this->belongsTo(VisaCase::class);
    }

    /** @return BelongsTo<VisaStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(VisaStep::class, 'visa_step_id');
    }

    /** @return BelongsTo<CandidateDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CandidateDocument::class, 'candidate_document_id');
    }
}
