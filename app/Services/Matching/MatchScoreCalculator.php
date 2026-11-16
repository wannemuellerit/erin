<?php

namespace App\Services\Matching;

use App\Models\MatchScoreVersion;

final class MatchScoreCalculator
{
    public const VERSION = '1.0';

    /**
     * @var array<string, int>
     */
    public const WEIGHTS = [
        'profession' => 25,
        'skills' => 20,
        'language' => 15,
        'experience' => 10,
        'employment' => 10,
        'availability' => 5,
        'salary' => 5,
        'relocation' => 5,
        'documents' => 5,
    ];

    /**
     * @param  array<string, float|int>  $factors  Values between 0 and 1.
     * @return array{version: string, score: int, factors: array<string, array{score: int, weight: int, contribution: float}>}
     */
    public function calculate(array $factors): array
    {
        $configuration = app()->bound('db')
            ? MatchScoreVersion::query()->where('status', 'active')->latest('activated_at')->first()
            : null;
        /** @var array<string, int> $weights */
        $weights = $configuration instanceof MatchScoreVersion ? $configuration->weights : self::WEIGHTS;
        $breakdown = [];
        $total = 0.0;

        foreach ($weights as $factor => $weight) {
            $score = max(0.0, min(1.0, (float) ($factors[$factor] ?? 0)));
            $contribution = round($score * $weight, 2);
            $total += $contribution;
            $breakdown[$factor] = [
                'score' => (int) round($score * 100),
                'weight' => $weight,
                'contribution' => $contribution,
            ];
        }

        return [
            'version' => $configuration instanceof MatchScoreVersion ? $configuration->version : self::VERSION,
            'score' => (int) round($total),
            'factors' => $breakdown,
        ];
    }
}
