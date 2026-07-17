<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $job_posting_id
 * @property int $candidate_profile_id
 * @property ApplicationStatus $status
 * @property string|null $match_score
 * @property array<string, mixed>|null $match_breakdown
 * @property Carbon $applied_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $identity_revealed_at
 * @property Carbon|null $documents_shared_at
 */
class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use HasFactory;

    protected $table = 'applications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'match_score' => 'decimal:2',
            'match_breakdown' => 'array',
            'applied_at' => 'datetime',
            'decided_at' => 'datetime',
            'identity_revealed_at' => 'datetime',
            'documents_shared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /**
     * @return HasMany<ApplicationStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'application_id')
            ->orderBy('created_at');
    }

    /**
     * @return HasMany<ApplicationScreeningAnswer, $this>
     */
    public function screeningAnswers(): HasMany
    {
        return $this->hasMany(ApplicationScreeningAnswer::class, 'application_id');
    }

    /**
     * @return HasMany<Interview, $this>
     */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'application_id');
    }

    /**
     * @return HasMany<CandidateInternalReview, $this>
     */
    public function internalReviews(): HasMany
    {
        return $this->hasMany(CandidateInternalReview::class, 'application_id');
    }

    /**
     * @return HasOne<VisaCase, $this>
     */
    public function visaCase(): HasOne
    {
        return $this->hasOne(VisaCase::class, 'application_id');
    }

    public function pipelineStage(): string
    {
        return $this->status->pipelineStage();
    }

    public function identityIsRevealed(): bool
    {
        return $this->identity_revealed_at !== null;
    }
}
