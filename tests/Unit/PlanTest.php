<?php

use App\Enums\Plan;

test('plans limit active engagements, not seats', function () {
    expect(Plan::Solo->activeEngagementLimit())->toBe(1)
        ->and(Plan::Studio->activeEngagementLimit())->toBe(25)
        ->and(Plan::Firm->activeEngagementLimit())->toBeNull();
});

test('every plan has a label', function () {
    expect(Plan::Solo->label())->toBe('Solo')
        ->and(Plan::Studio->label())->toBe('Studio')
        ->and(Plan::Firm->label())->toBe('Firm');
});
