<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Services\Access\AccessListResolver;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAccess
{
    public function __construct(
        private readonly AccessListResolver $accessList,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $status = $user->status->value;
        $access = $this->accessList->decide($user->email, $request->ip());

        if (
            in_array($status, [UserStatus::Suspended->value, UserStatus::Blocked->value], true)
            || $access->blocked()
        ) {
            if ($access->blocked()) {
                $this->audit->record(
                    'access.blocked.active_session',
                    $user,
                    metadata: [
                        'access_list_entry_id' => $access->matchedEntry?->getKey(),
                        'subject_type' => $access->matchedEntry?->subject_type,
                    ],
                    request: $request,
                );
            }

            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('Dein Erin-Konto ist derzeit gesperrt. Bitte kontaktiere den Support.'),
            ]);
        }

        return $next($request);
    }
}
