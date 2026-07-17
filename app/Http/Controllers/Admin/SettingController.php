<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Requests\Admin\UpdateThemeRequest;
use App\Models\PlatformSetting;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends AdminController
{
    public function index(PlatformSettings $settings): Response
    {
        return Inertia::render('admin/Settings', [
            'colors' => $settings->colors(),
            'defaults' => PlatformSettings::DEFAULT_COLORS,
            'dashboard_notice' => $settings->get('dashboard.barmer_notice', [
                'enabled' => true,
                'title_de' => 'Gemeinsam gut ankommen',
                'title_en' => 'Arrive well together',
                'body_de' => 'Nach erfolgreichem Visumspaket unterstützen wir bei der Anmeldung der neuen Mitarbeitenden bei der BARMER.',
                'body_en' => 'After a successful visa package, we support the registration of new employees with BARMER.',
                'url' => null,
            ]),
            'billing' => [
                'visa_credit_enabled' => (bool) $settings->get('billing.visa_credit_enabled', false),
                'visa_credit_price_cents' => $settings->get('billing.visa_credit_price_cents'),
                'seat_addon_enabled' => (bool) $settings->get('billing.seat_addon_enabled', false),
                'seat_addon_price_cents' => $settings->get('billing.seat_addon_price_cents'),
                'referral_commission_cents' => $settings->get('referrals.default_commission_cents'),
            ],
        ]);
    }

    public function updateTheme(
        UpdateThemeRequest $request,
        PlatformSettings $settings,
    ): RedirectResponse {
        $colors = $request->validated('colors');
        $before = $settings->colors();

        $settings->put(
            'theme.colors',
            $colors,
            'theme',
            true,
            $request->user()?->getKey(),
        );

        $setting = PlatformSetting::query()->where('key', 'theme.colors')->first();
        $this->audit(
            $request,
            'admin.platform_settings.theme_updated',
            $setting,
            $before,
            $colors,
        );

        return back()->with('success', __('Die Plattformfarben wurden aktualisiert.'));
    }

    public function update(
        UpdatePlatformSettingsRequest $request,
        PlatformSettings $settings,
    ): RedirectResponse {
        $validated = $request->validated();
        $before = [
            'dashboard_notice' => $settings->get('dashboard.barmer_notice'),
            'billing' => [
                'visa_credit_enabled' => $settings->get('billing.visa_credit_enabled', false),
                'visa_credit_price_cents' => $settings->get('billing.visa_credit_price_cents'),
                'seat_addon_enabled' => $settings->get('billing.seat_addon_enabled', false),
                'seat_addon_price_cents' => $settings->get('billing.seat_addon_price_cents'),
                'referral_commission_cents' => $settings->get('referrals.default_commission_cents'),
            ],
        ];

        $userId = $request->user()?->getKey();
        $settings->put(
            'dashboard.barmer_notice',
            $validated['dashboard_notice'],
            'dashboard',
            true,
            $userId,
        );
        $settings->put(
            'billing.visa_credit_enabled',
            $validated['billing']['visa_credit_enabled'],
            'billing',
            false,
            $userId,
        );
        $settings->put(
            'billing.visa_credit_price_cents',
            $validated['billing']['visa_credit_price_cents'] ?? null,
            'billing',
            false,
            $userId,
        );
        $settings->put(
            'billing.seat_addon_enabled',
            $validated['billing']['seat_addon_enabled'],
            'billing',
            false,
            $userId,
        );
        $settings->put(
            'billing.seat_addon_price_cents',
            $validated['billing']['seat_addon_price_cents'] ?? null,
            'billing',
            false,
            $userId,
        );
        $settings->put(
            'referrals.default_commission_cents',
            $validated['billing']['referral_commission_cents'] ?? null,
            'referrals',
            false,
            $userId,
        );

        $this->audit(
            $request,
            'admin.platform_settings.updated',
            after: $validated,
            before: $before,
        );

        return back()->with('success', __('Die Plattformkonfiguration wurde aktualisiert.'));
    }
}
