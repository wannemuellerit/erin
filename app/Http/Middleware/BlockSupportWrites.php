<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockSupportWrites
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->session()->has('impersonation_session_id')
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            abort(
                Response::HTTP_FORBIDDEN,
                __('Die Supportansicht ist schreibgeschützt. Beende sie, um Änderungen vorzunehmen.'),
            );
        }

        return $next($request);
    }
}
