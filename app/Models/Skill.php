<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Occupation, $this>
     */
    public function occupations(): BelongsToMany
    {
        return $this->belongsToMany(Occupation::class);
    }

    /**
     * @return BelongsToMany<CandidateProfile, $this>
     */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(CandidateProfile::class, 'candidate_skill')
            ->withPivot(['proficiency', 'experience_years', 'is_verified'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<JobPosting, $this>
     */
    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(JobPosting::class, 'job_skill')
            ->withPivot(['importance', 'minimum_experience_years']);
    }
}
