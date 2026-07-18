<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $support_ticket_message_id
 * @property int|null $uploaded_by
 * @property string $source
 * @property string|null $external_id
 * @property string|null $disk
 * @property string|null $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property string $scan_result
 */
class SupportTicketAttachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportTicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
