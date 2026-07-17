<?php

namespace App\Models;

use App\Enums\AiRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'input_manifest' => 'array',
            'output' => 'array',
            'requires_consent' => 'boolean',
            'started_at' => 'datetime',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<AiConsent, $this>
     */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(AiConsent::class);
    }

    public function canRun(): bool
    {
        return ! $this->requires_consent || $this->consent?->isActive() === true;
    }
}
