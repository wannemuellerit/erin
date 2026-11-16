<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $support_chat_session_id
 * @property string $author
 * @property string $body
 * @property list<int>|null $source_article_ids
 * @property bool $escalation_required
 * @property string|null $feedback
 * @property Carbon $retention_expires_at
 */
class SupportChatMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'source_article_ids' => 'array',
            'escalation_required' => 'boolean',
            'feedback_comment' => 'encrypted',
            'retention_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportChatSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SupportChatSession::class, 'support_chat_session_id');
    }
}
