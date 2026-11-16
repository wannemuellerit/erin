<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int|null $company_id
 * @property string $locale
 * @property string $status
 * @property string|null $current_route
 * @property int|null $handed_off_ticket_id
 * @property Carbon $last_activity_at
 * @property Carbon $retention_expires_at
 * @property-read Collection<int, SupportChatMessage> $messages
 */
class SupportChatSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'retention_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function handedOffTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'handed_off_ticket_id');
    }

    /** @return HasMany<SupportChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportChatMessage::class);
    }
}
