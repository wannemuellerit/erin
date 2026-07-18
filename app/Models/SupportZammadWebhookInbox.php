<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $delivery_id
 * @property string $external_ticket_id
 * @property string $payload_sha256
 * @property string $raw_payload
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $locked_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $terminal_at
 * @property string|null $last_error
 */
class SupportZammadWebhookInbox extends Model
{
    protected $table = 'support_zammad_webhook_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'processed_at' => 'datetime',
            'terminal_at' => 'datetime',
        ];
    }
}
