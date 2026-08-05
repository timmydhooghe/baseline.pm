<?php

use App\Enums\ChangeRequestStatus;

test('the lifecycle runs draft, assessment, proposal, awaiting approval', function () {
    expect(ChangeRequestStatus::Draft->canTransitionTo(ChangeRequestStatus::UnderAssessment))->toBeTrue()
        ->and(ChangeRequestStatus::Draft->canTransitionTo(ChangeRequestStatus::AwaitingApproval))->toBeFalse()
        ->and(ChangeRequestStatus::UnderAssessment->canTransitionTo(ChangeRequestStatus::CustomerProposal))->toBeTrue()
        ->and(ChangeRequestStatus::UnderAssessment->canTransitionTo(ChangeRequestStatus::Approved))->toBeFalse()
        ->and(ChangeRequestStatus::CustomerProposal->canTransitionTo(ChangeRequestStatus::AwaitingApproval))->toBeTrue()
        ->and(ChangeRequestStatus::CustomerProposal->canTransitionTo(ChangeRequestStatus::UnderAssessment))->toBeTrue();
});

test('an awaiting proposal is decided or returns to assessment on clarification', function () {
    expect(ChangeRequestStatus::AwaitingApproval->canTransitionTo(ChangeRequestStatus::Approved))->toBeTrue()
        ->and(ChangeRequestStatus::AwaitingApproval->canTransitionTo(ChangeRequestStatus::Rejected))->toBeTrue()
        ->and(ChangeRequestStatus::AwaitingApproval->canTransitionTo(ChangeRequestStatus::UnderAssessment))->toBeTrue()
        ->and(ChangeRequestStatus::AwaitingApproval->canTransitionTo(ChangeRequestStatus::Draft))->toBeFalse();
});

test('decisions are terminal', function () {
    expect(ChangeRequestStatus::Approved->allowedTransitions())->toBe([])
        ->and(ChangeRequestStatus::Rejected->allowedTransitions())->toBe([])
        ->and(ChangeRequestStatus::Approved->isDecided())->toBeTrue()
        ->and(ChangeRequestStatus::Rejected->isDecided())->toBeTrue()
        ->and(ChangeRequestStatus::AwaitingApproval->isDecided())->toBeFalse();
});

test('only assessment and proposal accept structured assessment edits', function () {
    foreach (ChangeRequestStatus::cases() as $status) {
        expect($status->acceptsAssessment())->toBe(in_array($status, [
            ChangeRequestStatus::UnderAssessment,
            ChangeRequestStatus::CustomerProposal,
        ], true));
    }
});

test('every status has a label', function () {
    foreach (ChangeRequestStatus::cases() as $status) {
        expect($status->label())->not->toBe('');
    }
});
