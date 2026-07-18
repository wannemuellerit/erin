<?php

namespace App\Console\Commands;

use App\Models\CandidateImport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneOperationalDataCommand extends Command
{
    protected $signature = 'erin:ops:prune
        {--execute : Daten tatsächlich gemäß freigegebener Fristen löschen}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Bereinigt freigegebene operative Daten mit sicherem Dry-Run';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $results = collect();

        $this->pruneTable(
            $results,
            'login_histories',
            'created_at',
            $this->days('login_history_days'),
            $execute,
        );
        $this->pruneTable(
            $results,
            'notifications',
            'created_at',
            $this->days('read_notification_days'),
            $execute,
            static fn ($query) => $query->whereNotNull('read_at'),
        );
        $this->pruneTable(
            $results,
            'activity_entries',
            'occurred_at',
            $this->days('activity_days'),
            $execute,
        );
        $this->pruneTable(
            $results,
            'failed_jobs',
            'failed_at',
            $this->days('failed_job_days'),
            $execute,
        );
        $this->pruneCandidateImports($results, $this->days('candidate_import_days'), $execute);

        $payload = [
            'mode' => $execute ? 'execute' : 'dry_run',
            'targets' => $results->values()->all(),
            'affected' => $results->sum('affected'),
        ];
        Log::info('ops.retention_prune', $payload);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Ziel', 'Frist', $execute ? 'Gelöscht' : 'Würde löschen', 'Status'],
            $results->map(static fn (array $result): array => [
                $result['target'],
                $result['days'] > 0 ? $result['days'].' Tage' : 'deaktiviert',
                $result['affected'],
                $result['enabled'] ? 'aktiv' : 'wartet auf Freigabe',
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     */
    private function pruneTable(
        Collection $results,
        string $table,
        string $column,
        int $days,
        bool $execute,
        ?callable $scope = null,
    ): void {
        if ($days <= 0) {
            $results->push($this->disabled($table));

            return;
        }

        $query = DB::table($table)->where($column, '<', now()->subDays($days));
        if ($scope !== null) {
            $scope($query);
        }
        $affected = $execute ? $query->delete() : $query->count();
        $results->push([
            'target' => $table,
            'days' => $days,
            'enabled' => true,
            'affected' => $affected,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     */
    private function pruneCandidateImports(Collection $results, int $days, bool $execute): void
    {
        if ($days <= 0) {
            $results->push($this->disabled('candidate_imports'));

            return;
        }

        $query = CandidateImport::query()
            ->whereNotIn('status', ['queued', 'processing'])
            ->where(function (Builder $query) use ($days): void {
                $cutoff = now()->subDays($days);
                $query->where('completed_at', '<', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('completed_at')->where('created_at', '<', $cutoff);
                    });
            });
        $affected = (clone $query)->count();

        if ($execute) {
            $query->chunkById(100, function (Collection $imports): void {
                foreach ($imports as $import) {
                    Storage::disk($import->disk)->delete($import->storage_path);
                    $import->delete();
                }
            });
        }

        $results->push([
            'target' => 'candidate_imports',
            'days' => $days,
            'enabled' => true,
            'affected' => $affected,
        ]);
    }

    private function days(string $key): int
    {
        return max(0, (int) config("operations.retention.{$key}", 0));
    }

    /**
     * @return array{target: string, days: int, enabled: bool, affected: int}
     */
    private function disabled(string $target): array
    {
        return [
            'target' => $target,
            'days' => 0,
            'enabled' => false,
            'affected' => 0,
        ];
    }
}
