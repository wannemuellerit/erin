<?php

namespace App\Http\Middleware;

use BackedEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;
        $roleValue = $role instanceof BackedEnum ? $role->value : (string) $role;

        abort_unless(in_array($roleValue, $roles, true), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
