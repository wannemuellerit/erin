<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

class RecordAuthenticationHistory
{
    public function handle(Login|Failed|Logout $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $email = $this->emailFor($event, $user);

        if ($email === null) {
            return;
        }

        LoginHistory::query()->create([
            'user_id' => $user?->getKey(),
            'email' => $email,
            'event' => $this->eventName($event),
            'successful' => ! $event instanceof Failed,
            'ip_address' => request()->ip(),
            'user_agent' => $this->userAgent(),
            'failure_reason' => $event instanceof Failed ? 'invalid_credentials' : null,
        ]);
    }

    private function emailFor(Login|Failed|Logout $event, ?User $user): ?string
    {
        if ($user !== null) {
            return Str::lower($user->email);
        }

        if (! $event instanceof Failed) {
            return null;
        }

        $email = $event->credentials['email'] ?? null;

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? Str::lower($email)
            : null;
    }

    private function eventName(Login|Failed|Logout $event): string
    {
        return match (true) {
            $event instanceof Login => 'login',
            $event instanceof Failed => 'failed',
            $event instanceof Logout => 'logout',
        };
    }

    private function userAgent(): ?string
    {
        $userAgent = request()->userAgent();

        return is_string($userAgent) && $userAgent !== ''
            ? Str::limit($userAgent, 1000, '')
            : null;
    }
}
