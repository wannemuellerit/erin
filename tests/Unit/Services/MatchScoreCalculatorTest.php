<?php

use App\Services\Matching\MatchScoreCalculator;

it('calculates a weighted and bounded match score', function () {
    $result = app(MatchScoreCalculator::class)->calculate([
        'profession' => 1,
        'skills' => .8,
        'language' => .6,
        'experience' => 1,
        'employment' => 1,
        'availability' => .5,
        'salary' => 1,
        'relocation' => 1,
        'documents' => 2,
    ]);

    expect($result['score'])->toBe(88)
        ->and($result['factors']['documents']['score'])->toBe(100)
        ->and(array_sum(MatchScoreCalculator::WEIGHTS))->toBe(100);
});
