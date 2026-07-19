<?php

namespace App\Models;

use App\Services\Candidates\ProfileCompletenessCalculator;
use Database\Factories\CandidateProfileFactory;
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
 * @property int $user_id
 * @property int|null $occupation_id
 * @property array<int, string>|null $driving_licenses
 * @property array<int, string>|null $employment_preferences
 * @property Carbon|null $available_from
 * @property int $completeness
 * @property Carbon|null $published_at
 * @property string|null $profile_photo_path
 * @property string|null $profile_photo_quarantine_path
 * @property string|null $profile_photo_disk
 * @property string|null $profile_photo_scan_result
 */
class CandidateProfile extends Model
{
    /** @use HasFactory<CandidateProfileFactory> */
    use HasFactory, Searchable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'driving_licenses' => 'array',
            'employment_preferences' => 'array',
            'travel_ready' => 'boolean',
            'relocation_ready' => 'boolean',
            'available_from' => 'date',
            'requires_visa' => 'boolean',
            'has_work_permit' => 'boolean',
            'profile_photo_scan_completed_at' => 'datetime',
            'published_at' => 'datetime',
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
     * @return BelongsTo<Occupation, $this>
     */
    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    /**
     * @return HasMany<CandidateExperience, $this>
     */
    public function experiences(): HasMany
    {
        return $this->hasMany(CandidateExperience::class);
    }

    /**
     * @return HasMany<CandidateEducation, $this>
     */
    public function educations(): HasMany
    {
        return $this->hasMany(CandidateEducation::class);
    }

    /**
     * @return HasMany<CandidateDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'candidate_skill')
            ->withPivot(['proficiency', 'experience_years', 'is_verified'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Language, $this>
     */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'candidate_language')
            ->withPivot(['level', 'is_verified'])
            ->withTimestamps();
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
     * @return HasMany<VisaCase, $this>
     */
    public function visaCases(): HasMany
    {
        return $this->hasMany(VisaCase::class);
    }

    /**
     * @return HasMany<CandidateInternalReview, $this>
     */
    public function internalReviews(): HasMany
    {
        return $this->hasMany(CandidateInternalReview::class);
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     * @return Builder<CandidateProfile>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed() && $this->published_at !== null;
    }

    /**
     * Only explicitly public, job-relevant attributes may leave the primary
     * database. In particular, this intentionally never loads the user,
     * documents, experiences, educations or identity/contact attributes.
     *
     * @return array<string, bool|float|int|string|array<int, int|string>|null>
     */
    public function toSearchableArray(): array
    {
        if (! $this->shouldBeSearchable()) {
            return [];
        }

        $this->loadMissing([
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
        $skillSlugs = $this->skills
            ->map(fn (Skill $skill): string => (string) $skill->getAttribute('slug'))
            ->filter()
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
                (string) $language->pivot->getAttribute('level'),
            ))
            ->values()
            ->all();

        return [
            'id' => (int) $this->getKey(),
            'current_country_code' => $this->current_country_code,
            'summary' => $this->summary,
            'current_position' => $this->current_position,
            'desired_position' => $this->desired_position,
            'occupation_id' => $this->occupation_id,
            'occupation_slug' => $this->occupation === null
                ? null
                : (string) $this->occupation->getAttribute('slug'),
            'occupation_names' => $occupationNames,
            'skill_ids' => $skillIds,
            'skill_slugs' => $skillSlugs,
            'skill_names' => $skillNames,
            'language_codes' => $languageCodes,
            'language_names' => $languageNames,
            'language_levels' => $languageLevels,
            'experience_years' => (float) $this->experience_years,
            'highest_qualification' => $this->highest_qualification,
            'driving_licenses' => array_values($this->driving_licenses ?? []),
            'employment_preferences' => array_values($this->employment_preferences ?? []),
            'weekly_hours' => $this->weekly_hours,
            'travel_ready' => (bool) $this->travel_ready,
            'relocation_ready' => (bool) $this->relocation_ready,
            'available_from' => $this->available_from?->toDateString(),
            'available_from_timestamp' => $this->available_from?->getTimestamp(),
            'salary_expectation_cents' => $this->salary_expectation_cents,
            'salary_currency' => $this->salary_currency,
            'requires_visa' => (bool) $this->requires_visa,
            'has_work_permit' => (bool) $this->has_work_permit,
            'profile_completeness' => (int) $this->completeness,
            'published_at' => $this->published_at?->getTimestamp(),
        ];
    }

    /**
     * @param  Collection<int, CandidateProfile>  $models
     * @return Collection<int, CandidateProfile>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        if ($models instanceof EloquentCollection) {
            $models->loadMissing([
                'occupation:id,slug,name_de,name_en',
                'skills:id,slug,name_de,name_en',
                'languages:id,code,name_de,name_en',
            ]);
        }

        return $models;
    }

    /**
     * @param  Builder<CandidateProfile>  $query
     * @return Builder<CandidateProfile>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with([
            'occupation:id,slug,name_de,name_en',
            'skills:id,slug,name_de,name_en',
            'languages:id,code,name_de,name_en',
        ]);
    }

    public function canApply(): bool
    {
        return $this->published_at !== null
            && $this->completeness >= app(ProfileCompletenessCalculator::class)->threshold();
    }

    public function anonymizedLabel(): string
    {
        return sprintf('%s · %s', $this->desired_position ?: $this->current_position, $this->current_country_code);
    }
}
