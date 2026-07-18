<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property SupportTicketStatus $status
 * @property int|null $external_updated_at_ms
 * @property int|null $external_last_article_at_ms
 * @property int $external_reconcile_attempts
 * @property Carbon|null $external_reconcile_not_before
 */
class SupportTicket extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'last_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'external_updated_at_ms' => 'integer',
            'external_last_article_at_ms' => 'integer',
            'external_reconcile_attempts' => 'integer',
            'external_reconcile_not_before' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    /**
     * @return HasOne<SupportTicketMessage, $this>
     */
    public function openingMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class)->oldestOfMany();
    }

    /**
     * @return HasMany<SupportZammadArticleReceipt, $this>
     */
    public function zammadArticleReceipts(): HasMany
    {
        return $this->hasMany(SupportZammadArticleReceipt::class);
    }
}
