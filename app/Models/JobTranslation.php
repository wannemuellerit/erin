<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobTranslation extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<JobPosting, $this> */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
