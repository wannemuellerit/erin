<?php

namespace App\Models;

use App\Enums\JobStatus;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $occupation_id
 * @property JobStatus $status
 * @property Carbon|null $boosted_until
 * @property Carbon|null $published_at
 */
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory, Searchable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'is_remote' => 'boolean',
            'visa_package_available' => 'boolean',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'boosted_until' => 'datetime',
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
     * @return BelongsTo<CompanyLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(CompanyLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Occupation, $this>
     */
    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    /**
     * @return HasMany<JobScreeningQuestion, $this>
     */
    public function screeningQuestions(): HasMany
    {
        return $this->hasMany(JobScreeningQuestion::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<JobMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(JobMedia::class);
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    /**
     * @return HasMany<JobInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(JobInvitation::class);
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'job_skill')
            ->withPivot(['importance', 'minimum_experience_years']);
    }

    /**
     * @return BelongsToMany<Language, $this>
     */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'job_language')
            ->withPivot(['minimum_level', 'is_required']);
    }

    /**
     * @param  Builder<JobPosting>  $query
     * @return Builder<JobPosting>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', JobStatus::Published->value)
            ->whereNotNull('published_at');
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed()
            && $this->status === JobStatus::Published
            && $this->published_at !== null;
    }

    /**
     * The job index contains public job/company data only. Billing details,
     * recruiter identities, exact addresses and private media metadata are
     * deliberately not loaded or serialized.
     *
     * @return array<string, bool|float|int|string|array<int, int|string>|null>
     */
    public function toSearchableArray(): array
    {
        if (! $this->shouldBeSearchable()) {
            return [];
        }

        $this->loadMissing([
            'company:id,name,slug,industry,city',
            'location:id,name,city,country_code',
            'occupation:id,slug,name_de,name_en',
            'skills:id,slug,name_de,name_en',
            'languages:id,code,name_de,name_en',
        ]);

        $occupationNames = $this->occupation === null
            ? []
            : array_values(array_filter([
                (string) $this->occupation->getAttribute('name_de'),
                (string) $this->occupation->getAttribute('name_en'),
            ]));
        $skillIds = $this->skills
            ->map(fn (Skill $skill): int => (int) $skill->getKey())
            ->values()
            ->all();
        $skillNames = $this->skills
            ->flatMap(fn (Skill $skill): array => [
                (string) $skill->getAttribute('name_de'),
                (string) $skill->getAttribute('name_en'),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $languageCodes = $this->languages
            ->map(fn (Language $language): string => $language->code)
            ->filter()
            ->values()
            ->all();
        $languageNames = $this->languages
            ->flatMap(fn (Language $language): array => [
                $language->name_de,
                $language->name_en,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $languageLevels = $this->languages
            ->map(fn (Language $language): string => sprintf(
                '%s:%s',
                $language->code,
                (string) $language->pivot->getAttribute('minimum_level'),
            ))
            ->values()
            ->all();

        return [
            'id' => (int) $this->getKey(),
            'company_id' => $this->company_id,
            'company_name' => $this->company?->name,
            'company_slug' => $this->company?->slug,
            'company_industry' => $this->company?->industry,
            'title' => $this->title,
            'slug' => $this->slug,
            'position' => $this->position,
            'description' => $this->description,
            'occupation_id' => $this->occupation_id,
            'occupation_slug' => $this->occupation === null
                ? null
                : (string) $this->occupation->getAttribute('slug'),
            'occupation_names' => $occupationNames,
            'skill_ids' => $skillIds,
            'skill_names' => $skillNames,
            'language_codes' => $languageCodes,
            'language_names' => $languageNames,
            'language_levels' => $languageLevels,
            'language_notes' => $this->language_notes,
            'location_city' => $this->location === null
                ? $this->company?->city
                : $this->location->city,
            'location_country_code' => $this->location?->country_code,
            'expected_experience_years' => $this->expected_experience_years === null
                ? null
                : (float) $this->expected_experience_years,
            'hours_min' => $this->hours_min,
            'hours_max' => $this->hours_max,
            'employment_type' => $this->employment_type,
            'compensation_min_cents' => $this->compensation_min_cents,
            'compensation_max_cents' => $this->compensation_max_cents,
            'currency' => $this->currency,
            'compensation_interval' => $this->compensation_interval,
            'status' => $this->status->value,
            'is_remote' => (bool) $this->is_remote,
            'visa_package_available' => (bool) $this->visa_package_available,
            'boosted_until' => $this->boosted_until?->getTimestamp(),
            'published_at' => $this->published_at?->getTimestamp(),
        ];
    }

    /**
     * @param  Collection<int, JobPosting>  $models
     * @return Collection<int, JobPosting>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        if ($models instanceof EloquentCollection) {
            $models->loadMissing([
                'company:id,name,slug,industry,city',
                'location:id,name,city,country_code',
                'occupation:id,slug,name_de,name_en',
                'skills:id,slug,name_de,name_en',
                'languages:id,code,name_de,name_en',
            ]);
        }

        return $models;
    }

    /**
     * @param  Builder<JobPosting>  $query
     * @return Builder<JobPosting>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with([
            'company:id,name,slug,industry,city',
            'location:id,name,city,country_code',
            'occupation:id,slug,name_de,name_en',
            'skills:id,slug,name_de,name_en',
            'languages:id,code,name_de,name_en',
        ]);
    }

    public function isBoosted(): bool
    {
        return $this->boosted_until?->isFuture() ?? false;
    }
}
