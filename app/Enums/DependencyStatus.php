<?php

namespace App\Enums;

/**
 * How far a dependency has travelled (FA-20): recorded, formally requested,
 * escalated after the date passed, then either received or waived. Received
 * and waived are terminal — the evidence trail behind them stays on record
 * and any delay it caused keeps its attribution.
 */
enum DependencyStatus: string
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Escalated = 'escalated';
    case Received = 'received';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Requested => 'Requested',
            self::Escalated => 'Escalated',
            self::Received => 'Received',
            self::Waived => 'Waived',
        };
    }

    /**
     * Whether the item is still owed — outstanding items accrue day-for-day
     * delay and appear on the owing party's action list.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Pending, self::Requested, self::Escalated], true);
    }
}
