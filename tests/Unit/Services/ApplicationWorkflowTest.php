<?php

use App\Enums\ApplicationStatus;
use App\Services\Applications\ApplicationWorkflow;

it('allows only explicit human application transitions', function () {
    $workflow = app(ApplicationWorkflow::class);

    expect($workflow->canTransition(
        ApplicationStatus::New,
        ApplicationStatus::InReview,
    ))->toBeTrue()
        ->and($workflow->canTransition(
            ApplicationStatus::New,
            ApplicationStatus::Hired,
        ))->toBeFalse()
        ->and(fn () => $workflow->assertCanTransition(
            ApplicationStatus::Rejected,
            ApplicationStatus::Hired,
        ))->toThrow(DomainException::class);
});
