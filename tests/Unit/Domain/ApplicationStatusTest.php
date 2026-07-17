<?php

use App\Enums\ApplicationStatus;

it('maps detailed application states to stable ATS columns', function (
    ApplicationStatus $status,
    string $expectedStage,
) {
    expect($status->pipelineStage())->toBe($expectedStage);
})->with([
    [ApplicationStatus::New, 'new'],
    [ApplicationStatus::DocumentsMissing, 'interesting'],
    [ApplicationStatus::InterviewScheduled, 'interview'],
    [ApplicationStatus::FinalSelection, 'final_selection'],
    [ApplicationStatus::ContractSigned, 'accepted'],
    [ApplicationStatus::Hired, 'hired'],
    [ApplicationStatus::Rejected, 'closed'],
]);

it('only treats closed outcomes as terminal', function () {
    expect(ApplicationStatus::Accepted->isTerminal())->toBeFalse()
        ->and(ApplicationStatus::Hired->isTerminal())->toBeTrue()
        ->and(ApplicationStatus::Rejected->isTerminal())->toBeTrue()
        ->and(ApplicationStatus::Withdrawn->isTerminal())->toBeTrue();
});
