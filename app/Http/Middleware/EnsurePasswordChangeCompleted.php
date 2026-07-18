<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChangeCompleted
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user !== null
            && $user->password_change_required_at !== null
            && ! $request->routeIs([
                'security.edit',
                'user-password.update',
                'password.confirm',
                'password.confirmation',
                'logout',
                'two-factor.*',
            ])
        ) {
            return redirect()->route('security.edit')->with(
                'warning',
                __('Vor der weiteren Nutzung ist ein Passwortwechsel erforderlich.'),
            );
        }

        return $next($request);
    }
}
