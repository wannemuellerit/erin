<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => fn (): bool => DB::selectOne('select 1 as healthy') !== null,
            'redis' => fn (): bool => (string) Redis::connection()->ping() !== '',
            'storage' => fn (): bool => $this->storage(),
            'search' => fn (): bool => ! config('erin.health.search_required')
                || Http::timeout(2)
                    ->withHeaders(['Authorization' => 'Bearer '.config('scout.meilisearch.key')])
                    ->get(rtrim((string) config('scout.meilisearch.host'), '/').'/health')
                    ->successful(),
            'clamav' => fn (): bool => ! config('erin.health.clamav_required')
                || $this->tcp(
                    (string) config('services.clamav.host', 'clamav'),
                    (int) config('services.clamav.port', 3310),
                ),
            'scheduler' => fn (): bool => Cache::get('erin:ops:scheduler-heartbeat') !== null,
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (Throwable) {
                $results[$name] = false;
            }
        }

        $healthy = ! in_array(false, $results, true);
        $response = [
            'status' => $healthy ? 'ready' : 'unavailable',
            'checked_at' => now()->toIso8601String(),
        ];

        if (app()->environment(['local', 'testing'])) {
            $response['checks'] = $results;
        }

        return response()->json($response, $healthy ? 200 : 503);
    }

    private function storage(): bool
    {
        $path = 'health/'.str()->uuid().'.probe';
        $disk = Storage::disk('private');
        $disk->put($path, 'erin-ready');
        $valid = $disk->get($path) === 'erin-ready';
        $disk->delete($path);

        return $valid;
    }

    private function tcp(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 2);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
