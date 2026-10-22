<?php

namespace App\Console\Commands;

use App\Services\Operations\LocalRestoreEvidenceValidator;
use Illuminate\Console\Command;

class VerifyLocalRestoreEvidenceCommand extends Command
{
    protected $signature = 'erin:ops:restore-evidence:verify
        {path : Pfad zur lokalen Restore-Drill-Evidenz}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Verifiziert lokale verschlüsselte Restore-Drill-Evidenz und ihre Artefakte';

    public function handle(LocalRestoreEvidenceValidator $validator): int
    {
        $path = $this->argument('path');
        if (! is_string($path)) {
            $this->error('Der Evidenzpfad muss eine Zeichenfolge sein.');

            return self::INVALID;
        }

        $result = $validator->validateFile($path);
        $exitCode = $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $exitCode;
        }

        $this->line($result['status'] === 'passed'
            ? 'Die lokale Restore-Drill-Evidenz ist technisch konsistent.'
            : 'Die lokale Restore-Drill-Evidenz ist ungültig.');

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $exitCode;
    }
}
