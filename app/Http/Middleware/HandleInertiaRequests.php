<?php

namespace App\Http\Middleware;

use App\Models\AdCampaign;
use App\Services\Authorization\CapabilityResolver;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $settings = app(PlatformSettings::class);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'status' => $user->status->value,
                    'locale' => $user->locale,
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'onboarding_completed' => $user->onboarding_completed_at !== null,
                ] : null,
                'companies' => fn (): array => $user?->companies()
                    ->select(['companies.id', 'companies.name', 'companies.slug', 'companies.status'])
                    ->wherePivotNotNull('accepted_at')
                    ->get()
                    ->toArray() ?? [],
                'active_company_id' => $request->session()->get('active_company_id'),
                'capabilities' => fn (): array => app(CapabilityResolver::class)->forRequest($request),
            ],
            'notifications' => fn (): array => $user ? [
                'unread_count' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()
                    ->latest()
                    ->limit(8)
                    ->get()
                    ->map(fn ($notification): array => [
                        'id' => $notification->id,
                        'type' => class_basename($notification->type),
                        'data' => $notification->data,
                        'read_at' => $notification->read_at?->toIso8601String(),
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ])
                    ->all(),
            ] : ['unread_count' => 0, 'items' => []],
            'theme' => fn (): array => $settings->colors(),
            'platform' => fn (): array => [
                'demo_mode' => (bool) config('app.demo_mode'),
                'locale' => app()->getLocale(),
                'supported_locales' => ['de', 'en'],
                'dashboard_ad' => $user ? $this->dashboardAd($settings, $user->role->value, $user->locale) : null,
            ],
            'impersonation' => fn (): ?array => $request->session()->has('impersonation_session_id') ? [
                'active' => true,
                'read_only' => true,
                'actor_name' => $request->session()->get('impersonator_name'),
                'reason' => $request->session()->get('impersonation_reason'),
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array{id: int, title: string, body: string, cta_label: string, url: string|null, media_url: string|null}|null
     */
    private function dashboardAd(
        PlatformSettings $settings,
        string $role,
        string $locale,
    ): ?array {
        $ad = array_replace(
            PlatformSettings::DEFAULT_DASHBOARD_AD,
            (array) $settings->getPublic('ads.dashboard', []),
        );
        $audience = (string) $ad['audience'];
        $roleAudience = $role === 'candidate' ? 'candidate' : ($role === 'company' ? 'company' : null);
        $startsAt = is_string($ad['starts_at']) && $ad['starts_at'] !== ''
            ? Carbon::parse($ad['starts_at'])
            : null;
        $endsAt = is_string($ad['ends_at']) && $ad['ends_at'] !== ''
            ? Carbon::parse($ad['ends_at'])
            : null;

        if (
            ! $ad['enabled']
            || $roleAudience === null
            || ($audience !== 'all' && $audience !== $roleAudience)
            || ($startsAt !== null && $startsAt->isFuture())
            || ($endsAt !== null && $endsAt->isPast())
        ) {
            return null;
        }

        $language = $locale === 'en' ? 'en' : 'de';
        $campaign = isset($ad['campaign_id'])
            ? AdCampaign::query()->whereKey($ad['campaign_id'])->first()
            : null;

        return [
            'id' => $campaign?->getKey() ?? 0,
            'title' => (string) $ad['title_'.$language],
            'body' => (string) $ad['body_'.$language],
            'cta_label' => (string) $ad['cta_label_'.$language],
            'url' => is_string($ad['url']) && $ad['url'] !== '' ? $ad['url'] : null,
            'media_url' => $campaign?->media_path
                ? URL::temporarySignedRoute('ads.media', now()->addMinutes(15), ['campaign' => $campaign])
                : null,
        ];
    }
}
