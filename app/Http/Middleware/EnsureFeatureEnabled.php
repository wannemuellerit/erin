<?php

namespace App\Http\Middleware;

use App\Services\Platform\FeatureFlagResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function __construct(private readonly FeatureFlagResolver $flags) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(
            $this->flags->enabledForRequest($feature, $request),
            404,
        );

        return $next($request);
    }
}
