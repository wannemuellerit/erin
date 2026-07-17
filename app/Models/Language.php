<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $code
 * @property string $name_de
 * @property string $name_en
 * @property Pivot $pivot
 */
class Language extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsToMany<CandidateProfile, $this>
     */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(CandidateProfile::class, 'candidate_language')
            ->withPivot(['level', 'is_verified'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<JobPosting, $this>
     */
    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(JobPosting::class, 'job_language')
            ->withPivot(['minimum_level', 'is_required']);
    }
}
