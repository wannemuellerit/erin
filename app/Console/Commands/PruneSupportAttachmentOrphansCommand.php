<?php

namespace App\Console\Commands;

use App\Services\Ticketing\SupportAttachmentOrphanCleaner;
use Illuminate\Console\Command;

class PruneSupportAttachmentOrphansCommand extends Command
{
    protected $signature = 'erin:support:prune-orphan-attachments
        {--execute : Verwaiste Zammad-Anhänge nach der Schutzfrist löschen}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Findet verwaiste private Zammad-Anhänge mit sicherer Schutzfrist';

    public function handle(SupportAttachmentOrphanCleaner $cleaner): int
    {
        $result = [
            'mode' => $this->option('execute') ? 'execute' : 'dry_run',
            ...$cleaner->prune((bool) $this->option('execute')),
        ];
        $failed = $result['metadata_errors'] > 0
            || $result['deletion_errors'] > 0;
        $result['status'] = $failed ? 'failed' : 'passed';
        $exitCode = $failed ? self::FAILURE : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return $exitCode;
        }

        $this->table(
            [
                'Status',
                'Modus',
                'Geprüft',
                'Löschbar',
                'Gelöscht',
                'Metadatenfehler',
                'Löschfehler',
            ],
            [[
                $result['status'],
                $result['mode'],
                $result['scanned'],
                $result['eligible'],
                $result['deleted'],
                $result['metadata_errors'],
                $result['deletion_errors'],
            ]],
        );

        return $exitCode;
    }
}
