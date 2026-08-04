<?php

use App\ValueObjects\Money;

test('money is built from integer cents', function () {
    $money = Money::fromCents(12345);

    expect($money->amount)->toBe(12345)
        ->and($money->currency)->toBe('EUR');
});

test('the currency is normalized to uppercase', function () {
    expect(Money::fromCents(100, 'eur')->currency)->toBe('EUR');
});

test('zero money is zero', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::fromCents(1)->isZero())->toBeFalse();
});

test('money can be added and subtracted', function () {
    $a = Money::fromCents(1000);
    $b = Money::fromCents(250);

    expect($a->add($b)->amount)->toBe(1250)
        ->and($a->subtract($b)->amount)->toBe(750);
});

test('money can be multiplied by an integer factor', function () {
    expect(Money::fromCents(1050)->multiply(3)->amount)->toBe(3150);
});

test('money can be negated', function () {
    expect(Money::fromCents(500)->negate()->amount)->toBe(-500)
        ->and(Money::fromCents(-500)->negate()->amount)->toBe(500)
        ->and(Money::fromCents(-1)->isNegative())->toBeTrue();
});

test('money compares within the same currency', function () {
    $small = Money::fromCents(100);
    $large = Money::fromCents(200);

    expect($large->greaterThan($small))->toBeTrue()
        ->and($small->lessThan($large))->toBeTrue()
        ->and($small->equals(Money::fromCents(100)))->toBeTrue()
        ->and($small->equals(Money::fromCents(100, 'USD')))->toBeFalse();
});

test('operations on mixed currencies are refused', function () {
    Money::fromCents(100, 'EUR')->add(Money::fromCents(100, 'USD'));
})->throws(InvalidArgumentException::class);

test('money formats with the european notation', function () {
    expect(Money::fromCents(123456)->format())->toBe('€ 1.234,56')
        ->and(Money::fromCents(-50)->format())->toBe('-€ 0,50')
        ->and(Money::fromCents(500)->format())->toBe('€ 5,00')
        ->and(Money::fromCents(1200, 'USD')->format())->toBe('USD 12,00');
});

test('money serializes to an explicit array shape', function () {
    expect(Money::fromCents(150)->toArray())->toBe([
        'amount' => 150,
        'currency' => 'EUR',
        'formatted' => '€ 1,50',
    ]);
});

test('operations return new instances instead of mutating', function () {
    $original = Money::fromCents(100);
    $original->add(Money::fromCents(50));

    expect($original->amount)->toBe(100);
});
