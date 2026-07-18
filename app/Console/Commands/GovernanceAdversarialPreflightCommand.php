<?php

namespace App\Console\Commands;

use App\Services\Operations\GovernanceAdversarialPreflight;
use Illuminate\Console\Command;

class GovernanceAdversarialPreflightCommand extends Command
{
    protected $signature = 'erin:ops:governance-adversarial
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Prüft das Governance-Gate gegen synthetische Manipulationsversuche';

    public function handle(GovernanceAdversarialPreflight $preflight): int
    {
        $result = $preflight->run();
        $exitCode = $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $exitCode;
        }

        $this->table(
            ['Angriff', 'Status', 'Erwartete Fehler'],
            array_map(
                static fn (array $attack): array => [
                    $attack['id'],
                    mb_strtoupper($attack['status']),
                    implode(', ', $attack['expected_errors']),
                ],
                $result['attacks'],
            ),
        );

        return $exitCode;
    }
}
