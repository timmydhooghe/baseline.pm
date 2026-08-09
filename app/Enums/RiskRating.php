<?php

namespace App\Enums;

/**
 * The three-point scale behind a risk's probability × impact rating
 * (FA-19). Probability doubles as the weighting applied to structured
 * exposure: a high-probability risk carries most of its euro exposure into
 * the margin risk band, a low-probability one carries a tenth of it (FA-17).
 */
enum RiskRating: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    /**
     * The rating's position on the scale, 1 (low) to 3 (high). Probability
     * times impact gives the 1..9 score that orders the register and decides
     * what escalates.
     */
    public function score(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    /**
     * The share of a risk's euro exposure that counts toward the weighted
     * total. Probability-weighting keeps the margin risk band honest: it is
     * neither the worst case nor nothing.
     */
    public function weight(): float
    {
        return match ($this) {
            self::Low => 0.1,
            self::Medium => 0.5,
            self::High => 0.9,
        };
    }
}
