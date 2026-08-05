<?php

use App\Enums\EngagementStatus;

test('the lifecycle is a forward chain with a review loop at each approval gate', function () {
    $expected = [
        'draft' => [EngagementStatus::PreparingBaseline],
        'preparing_baseline' => [EngagementStatus::AwaitingBaselineApproval],
        // A rejected or withdrawn baseline submission loops back to preparing.
        'awaiting_baseline_approval' => [EngagementStatus::Active, EngagementStatus::PreparingBaseline],
        'active' => [EngagementStatus::AwaitingFinalAcceptance],
        // A rejected, clarified or withdrawn final acceptance loops back to active.
        'awaiting_final_acceptance' => [EngagementStatus::Completed, EngagementStatus::Active],
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
