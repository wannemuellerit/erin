<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneStoredFilesCommand extends Command
{
    protected $signature = 'erin:storage:prune
        {--execute : Dateien und Datensätze tatsächlich löschen}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Bereinigt abgelehnte, abgelaufene und verwaiste private Uploads';

    public function handle(PlatformSettings $settings): int
    {
        $execute = (bool) $this->option('execute');
        $results = [];
        $results[] = $this->pruneRows(
            'candidate_documents',
            DB::table('candidate_documents')->where('status', 'rejected')->whereNull('deleted_at'),
            (int) $settings->get('retention.rejected_document_days', 0),
            $execute,
        );
        $results[] = $this->pruneRows(
            'message_attachments',
            DB::table('message_attachments'),
            (int) $settings->get('retention.message_attachment_days', 0),
            $execute,
        );
        $results[] = $this->pruneRows(
            'support_ticket_attachments',
            DB::table('support_ticket_attachments')->whereNotNull('path'),
            (int) $settings->get('retention.support_attachment_days', 0),
            $execute,
        );
        $results[] = $this->pruneOrphans(
            max(1, (int) $settings->get('retention.orphan_grace_hours', 24)),
            $execute,
        );

        $payload = [
            'mode' => $execute ? 'execute' : 'dry_run',
            'targets' => $results,
            'affected' => collect($results)->sum('affected'),
        ];
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{target: string, days: int, enabled: bool, affected: int}
     */
    private function pruneRows(string $table, Builder $query, int $days, bool $execute): array
    {
        if ($days <= 0) {
            return ['target' => $table, 'days' => 0, 'enabled' => false, 'affected' => 0];
        }

        $query->where('updated_at', '<', now()->subDays($days));
        $rows = $query->get(['id', 'disk', 'path']);

        if ($execute) {
            foreach ($rows as $row) {
                if ($row->path !== null) {
                    Storage::disk($row->disk ?: 'private')->delete($row->path);
                }
                DB::table($table)->where('id', $row->id)->delete();
            }
        }

        return [
            'target' => $table,
            'days' => $days,
            'enabled' => true,
            'affected' => $rows->count(),
        ];
    }

    /**
     * @return array{target: string, days: int, enabled: bool, affected: int}
     */
    private function pruneOrphans(int $graceHours, bool $execute): array
    {
        $references = collect([
            ...DB::table('candidate_documents')->whereNull('deleted_at')->pluck('path'),
            ...DB::table('candidate_profiles')->whereNotNull('profile_photo_path')->pluck('profile_photo_path'),
            ...DB::table('message_attachments')->pluck('path'),
            ...DB::table('support_ticket_attachments')->whereNotNull('path')->pluck('path'),
            ...DB::table('company_media')->pluck('path'),
            ...DB::table('job_media')->pluck('path'),
            ...DB::table('candidate_imports')->pluck('storage_path'),
            ...DB::table('gdpr_requests')->whereNotNull('export_path')->pluck('export_path'),
            ...DB::table('ad_campaigns')->whereNotNull('media_path')->pluck('media_path'),
        ])->filter()->flip();

        $disk = Storage::disk('private');
        $orphans = collect($disk->allFiles())
            ->filter(fn (string $path): bool => ! $references->has($path))
            ->filter(function (string $path) use ($disk, $graceHours): bool {
                try {
                    return $disk->lastModified($path) < now()->subHours($graceHours)->getTimestamp();
                } catch (\Throwable) {
                    return false;
                }
            })
            ->take(1000)
            ->values();

        if ($execute && $orphans->isNotEmpty()) {
            $disk->delete($orphans->all());
        }

        return [
            'target' => 'private_orphan_files',
            'days' => (int) ceil($graceHours / 24),
            'enabled' => true,
            'affected' => $orphans->count(),
        ];
    }
}
