<?php

namespace App\Casts;

use App\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a virtual `{attribute}` key backed by an `{attribute}_cents` integer
 * column to a Money value object. The currency comes from the cast argument,
 * e.g. `'unit_cost' => Money::class.':EUR'` (EUR when omitted).
 *
 * @implements CastsAttributes<Money, mixed>
 */
class AsMoney implements CastsAttributes
{
    public function __construct(private readonly string $currency = 'EUR') {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $cents = $attributes["{$key}_cents"] ?? null;

        if ($cents === null) {
            return null;
        }

        if (! is_int($cents) && ! (is_string($cents) && ctype_digit(ltrim($cents, '-')))) {
            throw new InvalidArgumentException("The [{$key}_cents] column must hold an integer amount of cents.");
        }

        return Money::fromCents((int) $cents, $this->currency);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return ["{$key}_cents" => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a ".Money::class.' instance.');
        }

        if ($value->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "The [{$key}] attribute expects {$this->currency}, got {$value->currency}.",
            );
        }

        return ["{$key}_cents" => $value->amount];
    }
}
