<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['de', 'en'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = ($user ? $user->locale : null)
            ?? $request->session()->get('locale')
            ?? config('app.locale');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'de';
        }

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }
}
