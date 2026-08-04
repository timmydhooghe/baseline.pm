<?php

namespace App\ValueObjects;

use App\Casts\AsMoney;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Monetary amount held as integer minor units (cents). Never floats.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class Money implements Arrayable, Castable, JsonSerializable
{
    private const array SYMBOLS = ['EUR' => '€'];

    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public static function fromCents(int $amount, string $currency = 'EUR'): self
    {
        return new self($amount, mb_strtoupper($currency));
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return self::fromCents(0, $currency);
    }

    public function add(self $other): self
    {
        $this->guardSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->guardSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->guardSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->guardSameCurrency($other);

        return $this->amount < $other->amount;
    }

    /**
     * Format for display, e.g. "€ 1.234,56" or "-USD 12,00".
     */
    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $major = number_format(intdiv(abs($this->amount), 100), 0, ',', '.');
        $minor = str_pad((string) (abs($this->amount) % 100), 2, '0', STR_PAD_LEFT);
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency;

        return "{$sign}{$symbol} {$major},{$minor}";
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param  array<int, string>  $arguments
     */
    public static function castUsing(array $arguments): AsMoney
    {
        return new AsMoney($arguments[0] ?? 'EUR');
    }

    private function guardSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine money in {$this->currency} with money in {$other->currency}.",
            );
        }
    }
}
