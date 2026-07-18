<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property string $name
 * @property string $token_hash
 * @property bool $allow_role_change
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class AdminBootstrapInvitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allow_role_change' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AdminBootstrapInvitation>  $query
     * @return Builder<AdminBootstrapInvitation>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }
}
