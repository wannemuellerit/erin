<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed>|null $payload
 * @property string $data_quality
 * @property Carbon $occurred_at
 */
class ActivityEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ActivityEntry $entry): void {
            $entry->event_uuid ??= (string) Str::uuid();
            $entry->schema_version ??= 1;
            $entry->data_quality ??= 'observed';
        });
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
