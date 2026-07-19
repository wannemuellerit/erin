<?php

namespace App\Http\Middleware;

use App\Services\Authorization\CapabilityResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCapability
{
    public function __construct(private readonly CapabilityResolver $capabilities) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $available = $this->capabilities->forRequest($request);

        abort_unless(
            collect($required)->contains(fn (string $capability): bool => in_array($capability, $available, true)),
            403,
        );

        return $next($request);
    }
}
