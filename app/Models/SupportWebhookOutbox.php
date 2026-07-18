<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportWebhookOutbox extends Model
{
    protected $table = 'support_webhook_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportTicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(
            SupportTicketMessage::class,
            'support_ticket_message_id',
        );
    }

    /**
     * @return BelongsTo<SupportTicketAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(
            SupportTicketAttachment::class,
            'support_ticket_attachment_id',
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
