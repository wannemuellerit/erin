<?php

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

class ActivateVerifiedUser
{
    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if ($event->user->status === UserStatus::Pending) {
            $event->user->forceFill(['status' => UserStatus::Active])->saveQuietly();
        }
    }
}
