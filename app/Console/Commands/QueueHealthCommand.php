<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueHealthCommand extends Command
{
    protected $signature = 'erin:ops:queue-health
        {--connection= : Zu prüfende Queue-Verbindung}
        {--queues= : Kommagetrennte Queue-Namen}
        {--max-pending= : Erlaubte Gesamtzahl wartender Jobs}
        {--max-failed= : Erlaubte Zahl fehlgeschlagener Jobs}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Erkennt Queue-Rückstau und fehlgeschlagene Jobs';

    public function handle(QueueFactory $queues): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('queue.default'));
        $queueNames = $this->queueNames();
        $maxPending = max(0, (int) ($this->option('max-pending') ?? config('operations.queue.max_pending')));
        $maxFailed = max(0, (int) ($this->option('max-failed') ?? config('operations.queue.max_failed')));

        try {
            $connection = $queues->connection($connectionName);
            $depths = [];
            foreach ($queueNames as $queueName) {
                $depths[$queueName] = $connection->size($queueName);
            }

            $pending = array_sum($depths);
            $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
            $healthy = $pending <= $maxPending && $failed <= $maxFailed;
            $payload = [
                'status' => $healthy ? 'healthy' : 'backpressure',
                'connection' => $connectionName,
                'queues' => $depths,
                'pending' => $pending,
                'failed' => $failed,
                'thresholds' => [
                    'max_pending' => $maxPending,
                    'max_failed' => $maxFailed,
                ],
            ];
        } catch (Throwable $exception) {
            $healthy = false;
            $payload = [
                'status' => 'unavailable',
                'connection' => $connectionName,
                'queues' => array_fill_keys($queueNames, null),
                'error_code' => 'queue_probe_failed',
            ];
            Log::error('Queue-Health-Prüfung fehlgeschlagen.', [
                'connection' => $connectionName,
                'queues' => $queueNames,
                'exception_class' => $exception::class,
            ]);
        }

        Log::log($healthy ? 'info' : 'error', 'ops.queue_health', $payload);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->table(
                ['Queue', 'Wartende Jobs'],
                collect($payload['queues'])
                    ->map(fn (mixed $depth, string $queue): array => [$queue, $depth ?? 'nicht verfügbar'])
                    ->values()
                    ->all(),
            );
            $this->line('Status: '.$payload['status']);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function queueNames(): array
    {
        $configured = $this->option('queues');
        if (is_string($configured) && trim($configured) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        $queues = config('operations.queue.queues', ['default']);

        return is_array($queues) && $queues !== [] ? array_values($queues) : ['default'];
    }
}
