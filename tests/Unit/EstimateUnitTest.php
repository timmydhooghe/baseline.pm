<?php

use App\Enums\EstimateUnit;

test('seconds format as hours because nobody plans work in seconds', function () {
    expect(EstimateUnit::Seconds->format(28800.0))->toBe('8h')
        ->and(EstimateUnit::Seconds->format(5400.0))->toBe('1.5h');
});

test('points and days format compactly', function () {
    expect(EstimateUnit::Points->format(3.0))->toBe('3 pts')
        ->and(EstimateUnit::Days->format(2.5))->toBe('2.5d')
        ->and(EstimateUnit::Days->format(2.0))->toBe('2d');
});
