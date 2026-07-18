<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    /**
     * Categories exposed in the account settings. More specific event keys
     * may still be stored and take precedence when resolving a notification.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'application',
        'interview',
        'message',
        'reminder',
        'support',
        'system',
    ];

    /**
     * @var array{
     *     database_enabled: bool,
     *     email_enabled: bool,
     *     push_enabled: bool,
     *     sms_enabled: bool,
     *     whatsapp_enabled: bool
     * }
     */
    public const DEFAULTS = [
        'database_enabled' => true,
        'email_enabled' => true,
        'push_enabled' => false,
        'sms_enabled' => false,
        'whatsapp_enabled' => false,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'database_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function categoryFor(string $event): string
    {
        $category = explode('.', $event, 2)[0];

        return in_array($category, self::EVENTS, true) ? $category : 'system';
    }
}
