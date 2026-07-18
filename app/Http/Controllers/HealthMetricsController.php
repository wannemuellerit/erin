<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthMetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $configuredToken = (string) config('erin.health.metrics_token');
        $providedToken = (string) $request->bearerToken();
        abort_if(
            $configuredToken === ''
            || $providedToken === ''
            || ! hash_equals($configuredToken, $providedToken),
            404,
        );

        $databaseStarted = hrtime(true);
        $databaseHealthy = $this->attempt(fn (): bool => DB::selectOne('select 1 as healthy') !== null);
        $databaseDuration = (hrtime(true) - $databaseStarted) / 1_000_000_000;
        $redisStarted = hrtime(true);
        $redisHealthy = $this->attempt(fn (): bool => (string) Redis::connection()->ping() !== '');
        $redisDuration = (hrtime(true) - $redisStarted) / 1_000_000_000;
        $heartbeat = Cache::get('erin:ops:scheduler-heartbeat');
        $schedulerLag = is_string($heartbeat)
            ? max(0, now()->diffInSeconds($heartbeat))
            : -1;
        $failedJobs = $this->attemptValue(
            fn (): int => (int) DB::table('failed_jobs')->count(),
            -1,
        );
        $queueBacklog = $this->attemptValue(
            fn (): int => (int) Redis::connection()->llen(
                'queues:'.(string) config('queue.connections.redis.queue', 'default'),
            ),
            -1,
        );

        $metrics = [
            '# HELP erin_dependency_up Whether a critical dependency responded successfully.',
            '# TYPE erin_dependency_up gauge',
            sprintf('erin_dependency_up{dependency="database"} %d', $databaseHealthy ? 1 : 0),
            sprintf('erin_dependency_up{dependency="redis"} %d', $redisHealthy ? 1 : 0),
            '# HELP erin_dependency_request_duration_seconds Dependency probe duration.',
            '# TYPE erin_dependency_request_duration_seconds gauge',
            sprintf('erin_dependency_request_duration_seconds{dependency="database"} %.6f', $databaseDuration),
            sprintf('erin_dependency_request_duration_seconds{dependency="redis"} %.6f', $redisDuration),
            '# HELP erin_queue_backlog_jobs Number of queued jobs awaiting processing.',
            '# TYPE erin_queue_backlog_jobs gauge',
            sprintf('erin_queue_backlog_jobs{queue="default"} %d', $queueBacklog),
            '# HELP erin_failed_jobs_total Number of retained failed jobs.',
            '# TYPE erin_failed_jobs_total gauge',
            sprintf('erin_failed_jobs_total %d', $failedJobs),
            '# HELP erin_scheduler_lag_seconds Age of the latest scheduler heartbeat, or -1 when absent.',
            '# TYPE erin_scheduler_lag_seconds gauge',
            sprintf('erin_scheduler_lag_seconds %d', $schedulerLag),
        ];

        return response(implode("\n", $metrics)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function attempt(callable $callback): bool
    {
        try {
            return $callback();
        } catch (Throwable) {
            return false;
        }
    }

    private function attemptValue(callable $callback, int $fallback): int
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
