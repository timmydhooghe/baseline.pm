<?php

use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exercises the `{attribute}` ⇄ `{attribute}_cents` cast convention on a
 * throwaway table; rate cards and baselines will use the same shape.
 */
#[Fillable(['unit_cost', 'list_price'])]
class MoneyCastModel extends Model
{
    protected $table = 'money_cast_models';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_cost' => Money::class,
            'list_price' => Money::class.':USD',
        ];
    }
}

beforeEach(function () {
    Schema::create('money_cast_models', function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('unit_cost_cents')->nullable();
        $table->bigInteger('list_price_cents')->nullable();
        $table->timestamps();
    });
});

test('money round-trips through the cents column', function () {
    $model = MoneyCastModel::create(['unit_cost' => Money::fromCents(12550)]);

    $this->assertDatabaseHas('money_cast_models', ['unit_cost_cents' => 12550]);

    $fresh = $model->fresh();

    expect($fresh->unit_cost)->toBeInstanceOf(Money::class)
        ->and($fresh->unit_cost->amount)->toBe(12550)
        ->and($fresh->unit_cost->currency)->toBe('EUR');
});

test('the cast honors its currency argument', function () {
    $model = MoneyCastModel::create(['list_price' => Money::fromCents(999, 'USD')]);

    expect($model->fresh()->list_price->currency)->toBe('USD');
});

test('assigning money in the wrong currency is refused', function () {
    MoneyCastModel::create(['list_price' => Money::fromCents(999, 'EUR')]);
})->throws(InvalidArgumentException::class);

test('assigning a non-money value is refused', function () {
    MoneyCastModel::create(['unit_cost' => 100]);
})->throws(InvalidArgumentException::class);

test('a null amount stays null', function () {
    $model = MoneyCastModel::create(['unit_cost' => null]);

    expect($model->fresh()->unit_cost)->toBeNull();
});
