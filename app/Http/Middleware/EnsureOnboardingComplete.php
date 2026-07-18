<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user !== null
            && in_array($user->role, [UserRole::Candidate, UserRole::Company], true)
            && $user->onboarding_completed_at === null
        ) {
            return redirect()->route('onboarding.show')->with(
                'warning',
                __('Bitte schließe zuerst die Einrichtung deines Kontos ab.'),
            );
        }

        return $next($request);
    }
}
