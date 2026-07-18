<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $mapping
 * @property array<string, mixed>|null $errors
 */
class CandidateImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<CandidateImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(CandidateImportRow::class);
    }
}
