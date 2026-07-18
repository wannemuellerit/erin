<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 */
class ActivityEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<ActivityEntry>  $query
     * @return Builder<ActivityEntry>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId)
            ->whereIn('visibility', ['company', 'shared']);
    }

    /**
     * @param  Builder<ActivityEntry>  $query
     * @return Builder<ActivityEntry>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('subject_user_id', $userId)
            ->whereIn('visibility', ['personal', 'shared']);
    }
}
