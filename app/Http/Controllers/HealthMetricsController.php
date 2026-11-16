<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthMetricsController extends Controller
{
    public function __invoke(Request $request, QueueFactory $queues): Response
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
        $queueBacklogs = [];
        $queueNames = config('operations.queue.queues', ['default']);
        foreach (is_array($queueNames) && $queueNames !== [] ? $queueNames : ['default'] as $queueName) {
            if (! is_string($queueName) || $queueName === '') {
                continue;
            }

            $queueBacklogs[$queueName] = $this->attemptValue(
                fn (): int => $queues->connection()->size($queueName),
                -1,
            );
        }
        $mailSuppressed = $this->attemptValue(
            fn (): int => (int) DB::table('email_suppressions')->whereNull('released_at')->count(),
            -1,
        );
        $mailFailures24h = $this->attemptValue(
            fn (): int => (int) DB::table('email_delivery_events')
                ->whereIn('event_type', ['hard_bounce', 'complaint'])
                ->where('occurred_at', '>=', now()->subDay())
                ->count(),
            -1,
        );
        $externalNotificationFailures24h = $this->attemptValue(
            fn (): int => (int) DB::table('external_notification_deliveries')
                ->whereIn('status', ['failed', 'rate_limited'])
                ->where('created_at', '>=', now()->subDay())->count(),
            -1,
        );
        $externalNotificationCostMicros = $this->attemptValue(
            fn (): int => (int) DB::table('external_notification_deliveries')
                ->where('created_at', '>=', now()->startOfMonth())->sum('cost_micros'),
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
            ...collect($queueBacklogs)
                ->map(fn (int $backlog, string $queue): string => sprintf(
                    'erin_queue_backlog_jobs{queue="%s"} %d',
                    addcslashes($queue, "\\\"\n\r"),
                    $backlog,
                ))
                ->values()
                ->all(),
            '# HELP erin_failed_jobs_total Number of retained failed jobs.',
            '# TYPE erin_failed_jobs_total gauge',
            sprintf('erin_failed_jobs_total %d', $failedJobs),
            '# HELP erin_scheduler_lag_seconds Age of the latest scheduler heartbeat, or -1 when absent.',
            '# TYPE erin_scheduler_lag_seconds gauge',
            sprintf('erin_scheduler_lag_seconds %d', $schedulerLag),
            '# HELP erin_mail_suppressed_recipients Number of currently suppressed email recipients.',
            '# TYPE erin_mail_suppressed_recipients gauge',
            sprintf('erin_mail_suppressed_recipients %d', $mailSuppressed),
            '# HELP erin_mail_delivery_failures_24h Hard bounces and complaints in the last 24 hours.',
            '# TYPE erin_mail_delivery_failures_24h gauge',
            sprintf('erin_mail_delivery_failures_24h %d', $mailFailures24h),
            '# HELP erin_external_notification_failures_24h Failed or rate-limited SMS and WhatsApp deliveries.',
            '# TYPE erin_external_notification_failures_24h gauge',
            sprintf('erin_external_notification_failures_24h %d', $externalNotificationFailures24h),
            '# HELP erin_external_notification_cost_micros_month Current-month provider cost in micros.',
            '# TYPE erin_external_notification_cost_micros_month gauge',
            sprintf('erin_external_notification_cost_micros_month %d', $externalNotificationCostMicros),
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
