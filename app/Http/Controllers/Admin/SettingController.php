<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Requests\Admin\UpdateThemeRequest;
use App\Models\AdCampaign;
use App\Models\Occupation;
use App\Models\PlatformRole;
use App\Models\PlatformSetting;
use App\Models\Skill;
use App\Services\Documents\UploadPolicy;
use App\Services\Platform\DashboardAdCampaignManager;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends AdminController
{
    public function index(
        PlatformSettings $settings,
        DashboardAdCampaignManager $campaigns,
    ): Response {
        $dashboardAd = array_replace(
            PlatformSettings::DEFAULT_DASHBOARD_AD,
            (array) $settings->get('ads.dashboard', []),
        );
        $campaign = $dashboardAd['campaign_id']
            ? AdCampaign::query()->whereKey($dashboardAd['campaign_id'])->first()
            : null;

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
            'uploads' => [
                'max_file_size_mb' => (int) $settings->get(
                    'uploads.max_file_size_mb',
                    PlatformSettings::DEFAULT_UPLOAD_LIMITS['max_file_size_mb'],
                ),
                'user_quota_mb' => (int) $settings->get(
                    'uploads.user_quota_mb',
                    PlatformSettings::DEFAULT_UPLOAD_LIMITS['user_quota_mb'],
                ),
            ],
            'candidate_profile' => [
                'minimum_completion' => (int) $settings->get('candidate_profile.minimum_completion', 80),
            ],
            'retention' => collect(PlatformSettings::DEFAULT_RETENTION)
                ->mapWithKeys(fn (int $default, string $key): array => [
                    $key => (int) $settings->get("retention.{$key}", $default),
                ]),
            'dashboard_ad' => $dashboardAd,
            'dashboard_ad_stats' => $campaigns->statistics($campaign?->getKey()),
            'dashboard_ad_media_url' => $campaign?->media_path
                ? URL::temporarySignedRoute('ads.media', now()->addMinutes(15), ['campaign' => $campaign])
                : null,
            'occupations' => Occupation::query()->orderBy('name_de')
                ->get(['id', 'name_de', 'name_en', 'is_active']),
            'skills' => Skill::query()->with('occupations:id')
                ->orderBy('name_de')->get(['id', 'slug', 'name_de', 'name_en', 'is_active']),
            'platform_roles' => PlatformRole::query()->withCount('users')->orderBy('name')->get(),
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
        DashboardAdCampaignManager $campaigns,
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
            'uploads' => [
                'max_file_size_mb' => $settings->get('uploads.max_file_size_mb'),
                'user_quota_mb' => $settings->get('uploads.user_quota_mb'),
            ],
            'candidate_profile' => [
                'minimum_completion' => $settings->get(
                    'candidate_profile.minimum_completion',
                    80,
                ),
            ],
            'dashboard_ad' => $settings->get('ads.dashboard'),
            'retention' => collect(PlatformSettings::DEFAULT_RETENTION)
                ->mapWithKeys(fn (int $default, string $key): array => [
                    $key => (int) $settings->get("retention.{$key}", $default),
                ]),
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
        $settings->put(
            'uploads.max_file_size_mb',
            $validated['uploads']['max_file_size_mb'],
            'uploads',
            false,
            $userId,
        );
        $settings->put(
            'uploads.user_quota_mb',
            $validated['uploads']['user_quota_mb'],
            'uploads',
            false,
            $userId,
        );
        foreach ($validated['retention'] ?? [] as $key => $value) {
            $settings->put("retention.{$key}", $value, 'retention', false, $userId);
        }
        if (isset($validated['candidate_profile']['minimum_completion'])) {
            $settings->put(
                'candidate_profile.minimum_completion',
                $validated['candidate_profile']['minimum_completion'],
                'matching',
                false,
                $userId,
            );
        }
        $campaign = $campaigns->sync($validated['dashboard_ad'], $request->user());
        $dashboardAd = [
            ...$validated['dashboard_ad'],
            'campaign_id' => $campaign->getKey(),
        ];
        $settings->put(
            'ads.dashboard',
            $dashboardAd,
            'ads',
            true,
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

    public function uploadAdMedia(
        Request $request,
        AdCampaign $campaign,
        UploadPolicy $uploads,
    ): RedirectResponse {
        $request->validate([
            'media' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,gif,webp',
                'max:'.$uploads->maxFileKilobytes(10 * 1024),
            ],
        ]);
        $file = $request->file('media');
        abort_if($file === null, 422);
        $path = $file->store("ads/{$campaign->public_id}", 'private');
        abort_if($path === false, 500);

        if ($campaign->media_path && $campaign->media_disk) {
            Storage::disk($campaign->media_disk)->delete($campaign->media_path);
        }
        $campaign->update([
            'media_disk' => 'private',
            'media_path' => $path,
            'media_mime' => $file->getMimeType(),
            'media_size_bytes' => $file->getSize(),
            'updated_by' => $request->user()?->getKey(),
        ]);
        $this->audit($request, 'admin.ad.media_updated', $campaign);

        return back()->with('success', __('Das Anzeigenmotiv wurde aktualisiert.'));
    }

    public function deleteAdMedia(Request $request, AdCampaign $campaign): RedirectResponse
    {
        if ($campaign->media_path && $campaign->media_disk) {
            Storage::disk($campaign->media_disk)->delete($campaign->media_path);
        }
        $campaign->update([
            'media_disk' => null,
            'media_path' => null,
            'media_mime' => null,
            'media_size_bytes' => null,
            'updated_by' => $request->user()?->getKey(),
        ]);
        $this->audit($request, 'admin.ad.media_deleted', $campaign);

        return back()->with('success', __('Das Anzeigenmotiv wurde entfernt.'));
    }
}
