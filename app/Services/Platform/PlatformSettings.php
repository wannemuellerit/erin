<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlatformSettings
{
    /** @var array{max_file_size_mb: int, user_quota_mb: int} */
    public const DEFAULT_UPLOAD_LIMITS = [
        'max_file_size_mb' => 50,
        'user_quota_mb' => 1024,
    ];

    /** @var array<string, bool|string|null> */
    public const DEFAULT_DASHBOARD_AD = [
        'enabled' => false,
        'audience' => 'all',
        'title_de' => '',
        'title_en' => '',
        'body_de' => '',
        'body_en' => '',
        'cta_label_de' => '',
        'cta_label_en' => '',
        'url' => null,
        'starts_at' => null,
        'ends_at' => null,
    ];

    /** @var array<string, string> */
    public const DEFAULT_COLORS = [
        'primary' => '#2563EB',
        'primary_hover' => '#1D4ED8',
        'secondary' => '#14B8A6',
        'accent' => '#F97316',
        'success' => '#22C55E',
        'warning' => '#F59E0B',
        'error' => '#EF4444',
        'info' => '#0EA5E9',
        'background' => '#F8FAFC',
        'surface' => '#FFFFFF',
        'surface_hover' => '#F1F5F9',
        'border' => '#E2E8F0',
        'divider' => '#CBD5E1',
        'text' => '#0F172A',
        'text_muted' => '#475569',
        'text_disabled' => '#94A3B8',
    ];

    /**
     * @return array<string, mixed>
     */
    public function public(): array
    {
        return Cache::remember('platform-settings.public', now()->addMinutes(10), function (): array {
            return DB::table('platform_settings')
                ->where('is_public', true)
                ->pluck('value', 'key')
                ->map(fn (mixed $value): mixed => $this->decode($value))
                ->all();
        });
    }

    /**
     * @return array<string, string>
     */
    public function colors(): array
    {
        $colors = $this->public()['theme.colors'] ?? [];

        return array_replace(
            self::DEFAULT_COLORS,
            is_array($colors) ? array_intersect_key($colors, self::DEFAULT_COLORS) : [],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = DB::table('platform_settings')->where('key', $key)->value('value');

        return $value === null ? $default : $this->decode($value);
    }

    public function getPublic(string $key, mixed $default = null): mixed
    {
        return $this->public()[$key] ?? $default;
    }

    public function put(
        string $key,
        mixed $value,
        string $group = 'general',
        bool $public = false,
        ?int $userId = null,
    ): void {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            [
                'group' => $group,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'is_public' => $public,
                'is_encrypted' => false,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Cache::forget('platform-settings.public');
    }

    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
