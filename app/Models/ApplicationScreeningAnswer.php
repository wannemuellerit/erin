<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationScreeningAnswer extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    /**
     * @return BelongsTo<JobScreeningQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(JobScreeningQuestion::class, 'job_screening_question_id');
    }
}
