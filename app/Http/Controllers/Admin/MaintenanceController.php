<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpdateMaintenanceModeRequest;
use App\Models\PlatformSetting;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;

class MaintenanceController extends AdminController
{
    public function update(
        UpdateMaintenanceModeRequest $request,
        PlatformSettings $settings,
    ): RedirectResponse {
        $before = (array) $settings->get('platform.maintenance', []);
        $validated = $request->validated();
        $after = [
            ...$validated,
            'translations' => $validated['translations'] ?? [],
            'started_at' => $validated['active']
                ? ($before['started_at'] ?? now()->toIso8601String())
                : null,
            'updated_at' => now()->toIso8601String(),
        ];

        $settings->put(
            'platform.maintenance',
            $after,
            'operations',
            true,
            $request->user()?->getKey(),
        );

        $setting = PlatformSetting::query()
            ->where('key', 'platform.maintenance')
            ->first();
        $this->audit(
            $request,
            $after['active']
                ? 'admin.maintenance.activated'
                : 'admin.maintenance.deactivated',
            $setting,
            $before,
            $after,
        );

        return back()->with('success', $after['active']
            ? __('Der Wartungsmodus wurde aktiviert.')
            : __('Der Wartungsmodus wurde deaktiviert.'));
    }
}
