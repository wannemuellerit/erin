<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlatformSettings;
use Illuminate\Contracts\View\View;
use Throwable;

class StatusController extends Controller
{
    public function __invoke(PlatformSettings $settings): View
    {
        try {
            $maintenance = (array) $settings->get('platform.maintenance', []);
        } catch (Throwable) {
            $maintenance = [];
        }

        $locale = app()->getLocale();
        $translations = is_array($maintenance['translations'] ?? null)
            ? $maintenance['translations']
            : [];

        return view('status', [
            'locale' => $locale,
            'active' => ($maintenance['active'] ?? false) === true,
            'message' => (string) ($translations[$locale]
                ?? (in_array($locale, ['de', 'en'], true) ? $maintenance["message_{$locale}"] ?? '' : '')),
            'expectedEndAt' => $maintenance['expected_end_at'] ?? null,
        ]);
    }
}
