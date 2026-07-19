<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $author_id
 * @property int|null $subject_user_id
 * @property int|null $subject_company_id
 * @property string|null $subject_type
 * @property int|null $subject_key
 * @property int|null $application_id
 * @property int|null $interview_id
 * @property string $sentiment
 * @property string $reason_code
 * @property string|null $comment
 * @property array<string, bool>|null $metrics
 * @property string $status
 */
class Feedback extends Model
{
    protected $table = 'feedbacks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function subjectCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'subject_company_id');
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    /**
     * @return BelongsTo<Interview, $this>
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }
}
