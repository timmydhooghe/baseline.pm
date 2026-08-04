<?php

namespace App\Enums;

enum Plan: string
{
    case Solo = 'solo';
    case Studio = 'studio';
    case Firm = 'firm';

    public function label(): string
    {
        return match ($this) {
            self::Solo => 'Solo',
            self::Studio => 'Studio',
            self::Firm => 'Firm',
        };
    }

    /**
     * How many non-archived engagements the plan allows, or null for unlimited.
     *
     * External stakeholders never consume paid seats — the only plan dimension
     * is the number of active (non-archived) engagements.
     */
    public function activeEngagementLimit(): ?int
    {
        return match ($this) {
            self::Solo => 1,
            self::Studio => 25,
            self::Firm => null,
        };
    }
}
