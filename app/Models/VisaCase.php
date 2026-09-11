<?php

namespace App\Models;

use App\Enums\VisaCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $candidate_profile_id
 * @property VisaCaseStatus $status
 * @property int $version
 * @property int|null $assigned_to
 * @property string $credit_status
 * @property string|null $credit_source
 * @property int|null $credit_usage_period_id
 * @property int|null $credit_ledger_id
 * @property-read JobApplication|null $application
 * @property-read Company $company
 * @property-read CandidateProfile $candidateProfile
 * @property-read User|null $assignee
 */
class VisaCase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VisaCaseStatus::class,
            'version' => 'integer',
            'assigned_to' => 'integer',
            'credit_usage_period_id' => 'integer',
            'credit_ledger_id' => 'integer',
            'target_start_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<VisaStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(VisaStep::class);
    }

    /** @return HasMany<VisaCaseDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VisaCaseDocument::class);
    }

    /** @return HasMany<VisaCaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(VisaCaseEvent::class);
    }
}
