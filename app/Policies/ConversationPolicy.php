<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->isPlatformStaff()
            || $conversation->participants()->whereKey($user->id)->exists();
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return ! $user->isSupport()
            && $conversation->participants()->whereKey($user->id)->exists();
    }
}
