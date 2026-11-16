<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\Platform\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforceMaintenanceMode
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->alwaysAvailable($request) || $this->staffBypass($request)) {
            return $next($request);
        }

        try {
            $maintenance = (array) $this->settings->get('platform.maintenance', []);
        } catch (Throwable) {
            return $next($request);
        }

        if (($maintenance['active'] ?? false) !== true) {
            return $next($request);
        }

        $locale = app()->getLocale();
        $translations = is_array($maintenance['translations'] ?? null)
            ? $maintenance['translations']
            : [];
        $message = (string) ($translations[$locale]
            ?? (in_array($locale, ['de', 'en'], true) ? $maintenance["message_{$locale}"] ?? '' : ''));

        return response()->view('maintenance', [
            'locale' => $locale,
            'message' => $message,
            'expectedEndAt' => $maintenance['expected_end_at'] ?? null,
        ], 503, [
            'Cache-Control' => 'no-store, private',
            'Retry-After' => '300',
        ]);
    }

    private function alwaysAvailable(Request $request): bool
    {
        return $request->is('status', 'up', 'health/*', 'billing/webhook', 'integrations/*');
    }

    private function staffBypass(Request $request): bool
    {
        return in_array($request->user()?->role, [
            UserRole::SuperAdmin,
            UserRole::Support,
        ], true);
    }
}
