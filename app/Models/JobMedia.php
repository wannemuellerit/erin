<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $job_posting_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string $scan_result
 * @property Carbon|null $scan_completed_at
 * @property-read JobPosting $jobPosting
 */
class JobMedia extends Model
{
    protected $table = 'job_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scan_completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
