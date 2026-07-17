<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffTwoFactor
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if (
            $user->isPlatformStaff()
            && $user->two_factor_confirmed_at === null
            && ! app()->environment(['local', 'testing'])
            && ! config('app.demo_mode')
        ) {
            return redirect()->route('security.edit')->with(
                'warning',
                __('Für Plattformrollen ist Zwei-Faktor-Authentifizierung verpflichtend.'),
            );
        }

        return $next($request);
    }
}
