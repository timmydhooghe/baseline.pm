<?php

use App\Enums\EngagementStatus;

test('the lifecycle is a strict forward chain', function () {
    $expected = [
        'draft' => [EngagementStatus::PreparingBaseline],
        'preparing_baseline' => [EngagementStatus::AwaitingBaselineApproval],
        'awaiting_baseline_approval' => [EngagementStatus::Active],
        'active' => [EngagementStatus::AwaitingFinalAcceptance],
        'awaiting_final_acceptance' => [EngagementStatus::Completed],
        'completed' => [EngagementStatus::Archived],
        'archived' => [],
    ];

    foreach (EngagementStatus::cases() as $status) {
        expect($status->allowedTransitions())->toBe($expected[$status->value]);
    }
});

test('canTransitionTo only allows the next step', function () {
    expect(EngagementStatus::Draft->canTransitionTo(EngagementStatus::PreparingBaseline))->toBeTrue()
        ->and(EngagementStatus::Draft->canTransitionTo(EngagementStatus::Active))->toBeFalse()
        ->and(EngagementStatus::Active->canTransitionTo(EngagementStatus::Draft))->toBeFalse()
        ->and(EngagementStatus::Archived->canTransitionTo(EngagementStatus::Draft))->toBeFalse();
});

test('every status except archived counts toward the plan limit', function () {
    foreach (EngagementStatus::cases() as $status) {
        expect($status->countsTowardPlanLimit())->toBe($status !== EngagementStatus::Archived);
    }
});

test('every status has a label', function () {
    foreach (EngagementStatus::cases() as $status) {
        expect($status->label())->not->toBe('');
    }
});
