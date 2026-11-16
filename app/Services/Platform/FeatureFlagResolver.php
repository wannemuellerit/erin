<?php

namespace App\Services\Platform;

use App\Models\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FeatureFlagResolver
{
    private const CACHE_KEY = 'erin.feature-flags.v1';

    public function enabledForRequest(
        string $key,
        Request $request,
        bool $default = true,
    ): bool {
        $flag = $this->flags()[$key] ?? null;

        if (! is_array($flag)) {
            return $default;
        }

        return $this->enabled(
            $flag,
            $request->user()?->getKey(),
            $request->user()?->role->value,
            $this->activeCompanyId($request),
            $request->user()
                ? 'user:'.$request->user()->getKey()
                : 'session:'.$request->session()->getId(),
        );
    }

    /**
     * @return array<string, bool>
     */
    public function decisionsForRequest(Request $request): array
    {
        $decisions = [];

        foreach (array_keys($this->flags()) as $key) {
            $decisions[$key] = $this->enabledForRequest($key, $request);
        }

        return $decisions;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function flags(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function (): array {
                return FeatureFlag::query()
                    ->get(['key', 'enabled', 'rollout_percentage', 'conditions'])
                    ->mapWithKeys(fn (FeatureFlag $flag): array => [
                        $flag->key => [
                            'key' => $flag->key,
                            'enabled' => $flag->enabled,
                            'rollout_percentage' => $flag->rollout_percentage,
                            'conditions' => $flag->conditions,
                        ],
                    ])
                    ->all();
            });
        } catch (Throwable) {
            // Deployment, migration and recovery paths must keep working when
            // the governance table or cache backend is temporarily unavailable.
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $flag
     */
    private function enabled(
        array $flag,
        ?int $userId,
        ?string $role,
        ?int $companyId,
        string $identity,
    ): bool {
        if (($flag['enabled'] ?? false) !== true) {
            return false;
        }

        $conditions = is_array($flag['conditions'] ?? null)
            ? $flag['conditions']
            : [];

        if (! $this->withinWindow($conditions)) {
            return false;
        }

        if (! $this->matchesList($conditions['roles'] ?? null, $role)) {
            return false;
        }

        if (! $this->matchesList($conditions['user_ids'] ?? null, $userId)) {
            return false;
        }

        if (! $this->matchesList($conditions['company_ids'] ?? null, $companyId)) {
            return false;
        }

        $percentage = max(0, min(100, (int) ($flag['rollout_percentage'] ?? 0)));

        if ($percentage === 100) {
            return true;
        }

        if ($percentage === 0) {
            return false;
        }

        $hash = hash('sha256', (string) ($flag['key'] ?? '').'|'.$identity, true);
        $bucket = unpack('Nbucket', substr($hash, 0, 4));

        return ((int) ($bucket['bucket'] ?? 0) % 100) < $percentage;
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function withinWindow(array $conditions): bool
    {
        try {
            $startsAt = filled($conditions['starts_at'] ?? null)
                ? Carbon::parse((string) $conditions['starts_at'])
                : null;
            $endsAt = filled($conditions['ends_at'] ?? null)
                ? Carbon::parse((string) $conditions['ends_at'])
                : null;
        } catch (Throwable) {
            return false;
        }

        return ! ($startsAt?->isFuture() ?? false)
            && ! ($endsAt?->isPast() ?? false);
    }

    private function matchesList(mixed $configured, int|string|null $actual): bool
    {
        if (! is_array($configured) || $configured === []) {
            return true;
        }

        if ($actual === null) {
            return false;
        }

        return in_array((string) $actual, array_map('strval', $configured), true);
    }

    private function activeCompanyId(Request $request): ?int
    {
        $companyId = $request->session()->get('active_company_id');

        return is_numeric($companyId) ? (int) $companyId : null;
    }
}
