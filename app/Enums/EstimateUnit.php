<?php

namespace App\Enums;

/**
 * The unit a work item's effort estimate arrived in: Jira estimates in
 * seconds, Linear in points, manual items in days. Stored raw with its unit —
 * converting to cost is a rate-card concern that happens at analysis time
 * (FA-9), never at import.
 */
enum EstimateUnit: string
{
    case Seconds = 'seconds';
    case Points = 'points';
    case Days = 'days';

    /**
     * The working-day divisor used when converting logged or estimated time
     * to days for rate-card costing.
     */
    public const float HOURS_PER_DAY = 8.0;

    /**
     * Convert an estimate to working days for rate-card costing (FA-9), or
     * null when the unit has no time equivalence: points are relative
     * sizing, and inventing a conversion would be a free-typed number in
     * disguise.
     */
    public function toDays(float $value): ?float
    {
        return match ($this) {
            self::Seconds => $value / 3600 / self::HOURS_PER_DAY,
            self::Points => null,
            self::Days => $value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Seconds => 'Hours',
            self::Points => 'Points',
            self::Days => 'Days',
        };
    }

    /**
     * A compact display form of an estimate in this unit; seconds render as
     * hours because nobody plans work in seconds.
     */
    public function format(float $value): string
    {
        $formatted = fn (float $number): string => rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');

        return match ($this) {
            self::Seconds => $formatted($value / 3600).'h',
            self::Points => $formatted($value).' pts',
            self::Days => $formatted($value).'d',
        };
    }
}
