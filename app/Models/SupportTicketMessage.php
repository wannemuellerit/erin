<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $external_reconcile_attempts
 * @property Carbon|null $external_reconcile_not_before
 */
class SupportTicketMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'attachments' => 'array',
            'delivered_at' => 'datetime',
            'external_reconcile_attempts' => 'integer',
            'external_reconcile_not_before' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<SupportTicketAttachment, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }
}
