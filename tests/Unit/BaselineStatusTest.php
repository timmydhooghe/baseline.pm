<?php

use App\Enums\BaselineStatus;

test('a draft baseline can only be submitted', function () {
    expect(BaselineStatus::Draft->canTransitionTo(BaselineStatus::AwaitingApproval))->toBeTrue()
        ->and(BaselineStatus::Draft->canTransitionTo(BaselineStatus::Approved))->toBeFalse()
        ->and(BaselineStatus::Draft->canTransitionTo(BaselineStatus::Draft))->toBeFalse();
});

test('a submitted baseline is approved or returns to draft', function () {
    expect(BaselineStatus::AwaitingApproval->canTransitionTo(BaselineStatus::Approved))->toBeTrue()
        ->and(BaselineStatus::AwaitingApproval->canTransitionTo(BaselineStatus::Draft))->toBeTrue();
});

test('an approved baseline never changes status', function () {
    expect(BaselineStatus::Approved->allowedTransitions())->toBe([]);
});

test('every status has a label', function () {
    foreach (BaselineStatus::cases() as $status) {
        expect($status->label())->not->toBe('');
    }
});
