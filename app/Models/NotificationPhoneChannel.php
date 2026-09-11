<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $channel
 * @property string $phone_e164
 * @property string $phone_hash
 * @property string $country_code
 * @property string|null $verification_code_hash
 * @property Carbon|null $verification_expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $consented_at
 * @property Carbon|null $revoked_at
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 */
class NotificationPhoneChannel extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['phone_e164', 'phone_hash', 'verification_code_hash'];

    protected function casts(): array
    {
        return [
            'phone_e164' => 'encrypted',
            'verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
            'verification_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canReceive(): bool
    {
        return $this->verified_at !== null
            && $this->consented_at !== null
            && $this->revoked_at === null;
    }
}
