<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string|null $media_disk
 * @property string|null $media_path
 * @property string|null $media_mime
 * @property bool $enabled
 * @property string $audience
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class AdCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AdEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AdEvent::class);
    }
}
