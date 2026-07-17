<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAccess
{
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

        if (in_array($status, [UserStatus::Suspended->value, UserStatus::Blocked->value], true)) {
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
