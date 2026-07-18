<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'experience_years' => 'decimal:1',
            'payload' => 'array',
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CandidateImport, $this>
     */
    public function candidateImport(): BelongsTo
    {
        return $this->belongsTo(CandidateImport::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
