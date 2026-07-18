<?php

namespace App\Console\Commands;

use App\Services\Operations\SyntheticPilotDrill;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class RunSyntheticPilotDrillCommand extends Command
{
    protected $signature = 'erin:ops:pilot-drill
        {--scenario=pass : Auszuführendes synthetisches Szenario}
        {--confirm= : Muss SYNTHETIC_LOCAL_ONLY entsprechen}
        {--output= : Relativer Evidenzpfad unter storage/app/operations/evidence}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Wertet das lokale synthetische Pilot-Kontrollmodell aus';

    public function handle(SyntheticPilotDrill $drill): int
    {
        if ((string) $this->option('confirm') !== 'SYNTHETIC_LOCAL_ONLY') {
            $this->error('Bestätigung fehlt: --confirm=SYNTHETIC_LOCAL_ONLY');

            return self::INVALID;
        }
        if (config('app.env') === 'production') {
            $this->error('Der synthetische Pilot-Drill darf nicht in der Produktion laufen.');

            return self::FAILURE;
        }

        $scenario = (string) $this->option('scenario');
        if (! in_array($scenario, $drill->scenarios(), true)) {
            $this->error('Unbekanntes Szenario. Erlaubt: '.implode(', ', $drill->scenarios()));

            return self::INVALID;
        }

        $startedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $result = $drill->run(
            $scenario,
            $startedAt,
            now()->utc()->format('Y-m-d\TH:i:s\Z'),
        );
        try {
            $relativePath = $this->outputPath($scenario);
            $path = storage_path('app/operations/evidence/'.$relativePath);
            $this->writeEvidence($path, $result);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result['evidence_path'] = $path;
        $result['evidence_sha256'] = hash_file('sha256', $path);
        $exitCode = ($result['result']['status'] ?? null) === 'passed'
            ? self::SUCCESS
            : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $exitCode;
        }

        $this->line("Synthetisches Pilot-Kontrollmodell: {$result['result']['status']}");
        $this->line("Evidenz: {$path}");

        return $exitCode;
    }

    private function outputPath(string $scenario): string
    {
        $configured = $this->option('output');
        if ($configured === null || $configured === '') {
            return sprintf(
                'pilot/pilot-%s-%s-%s.json',
                $scenario,
                now()->utc()->format('Ymd\THis\Z'),
                Str::lower(Str::random(8)),
            );
        }

        $relativePath = (string) $configured;
        if (
            str_starts_with($relativePath, '/')
            || str_contains($relativePath, '..')
            || str_contains($relativePath, '\\')
            || preg_match('/\A[a-zA-Z0-9._\/-]+\.json\z/', $relativePath) !== 1
        ) {
            throw new RuntimeException('Unsicherer Evidenzpfad.');
        }

        return $relativePath;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeEvidence(string $path, array $result): void
    {
        $root = storage_path('app/operations/evidence');
        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            throw new RuntimeException('Evidenzwurzel konnte nicht erstellt werden.');
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Evidenzverzeichnis konnte nicht erstellt werden.');
        }

        $realRoot = realpath($root);
        $realDirectory = realpath($directory);
        if (
            $realRoot === false
            || $realDirectory === false
            || is_link($directory)
            || (
                $realDirectory !== $realRoot
                && ! str_starts_with($realDirectory, $realRoot.DIRECTORY_SEPARATOR)
            )
        ) {
            throw new RuntimeException('Unsicheres Evidenzverzeichnis.');
        }

        try {
            $encoded = json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'Evidenz konnte nicht serialisiert werden.',
                previous: $exception,
            );
        }

        $jsonCreated = false;
        try {
            $this->writeExclusiveFile($path, $encoded."\n");
            $jsonCreated = true;
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new RuntimeException('Evidenz-Prüfsumme konnte nicht berechnet werden.');
            }
            $this->writeExclusiveFile(
                $path.'.sha256',
                $hash.'  '.basename($path)."\n",
            );
        } catch (RuntimeException $exception) {
            if ($jsonCreated && is_file($path) && ! is_link($path)) {
                unlink($path);
            }

            throw $exception;
        }
    }

    private function writeExclusiveFile(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp-'.Str::lower(Str::random(16));
        $stream = @fopen($temporaryPath, 'x+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('Temporäre Evidenzdatei konnte nicht exklusiv erstellt werden.');
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($stream, substr($contents, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Evidenzdatei konnte nicht vollständig geschrieben werden.');
                }
                $offset += $written;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Evidenzdatei konnte nicht synchronisiert werden.');
            }
            if (function_exists('fsync') && ! fsync($stream)) {
                throw new RuntimeException('Evidenzdatei konnte nicht dauerhaft synchronisiert werden.');
            }
            if (! chmod($temporaryPath, 0640)) {
                throw new RuntimeException('Evidenzdatei konnte nicht restriktiv berechtigt werden.');
            }
        } catch (RuntimeException $exception) {
            if (is_file($temporaryPath) && ! is_link($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $exception;
        } finally {
            fclose($stream);
        }

        try {
            if (is_link($path) || file_exists($path) || ! @link($temporaryPath, $path)) {
                throw new RuntimeException(
                    'Evidenzdatei existiert bereits oder kann nicht exklusiv erstellt werden.',
                );
            }
        } finally {
            if (is_file($temporaryPath) && ! is_link($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
