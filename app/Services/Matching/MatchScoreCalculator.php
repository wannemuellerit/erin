<?php

namespace App\Services\Matching;

final class MatchScoreCalculator
{
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
     * @return array{score: int, factors: array<string, array{score: int, weight: int, contribution: float}>}
     */
    public function calculate(array $factors): array
    {
        $breakdown = [];
        $total = 0.0;

        foreach (self::WEIGHTS as $factor => $weight) {
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
            'score' => (int) round($total),
            'factors' => $breakdown,
        ];
    }
}
