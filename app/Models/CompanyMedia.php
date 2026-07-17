<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $uploaded_by
 * @property string $type
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string $scan_result
 * @property Carbon|null $scan_completed_at
 * @property-read Company $company
 */
class CompanyMedia extends Model
{
    protected $table = 'company_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scan_completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
