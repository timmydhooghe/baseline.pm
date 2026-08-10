<?php

namespace App\Enums;

/**
 * Where a weekly burn line's day count came from (FA-16). The hierarchy runs
 * top down: a time-tracking integration prefills what it knows, profiles
 * without worklogs get a progress-derived suggestion, and manual entry is
 * always available. Every value stays editable until the week is recorded, so
 * a suggested figure a manager changed is recorded as manual — the ledger says
 * who decided the number, not who first proposed it.
 */
enum BurnSource: string
{
    case Worklog = 'worklog';
    case Progress = 'progress';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Worklog => 'Worklog',
            self::Progress => 'Progress estimate',
            self::Manual => 'Manual',
        };
    }

    /**
     * How the figure was arrived at, for the line's provenance tooltip.
     */
    public function description(): string
    {
        return match ($this) {
            self::Worklog => 'Logged time from the connected tool.',
            self::Progress => 'Derived from deliverable progress against the planned role mix.',
            self::Manual => 'Entered by hand.',
        };
    }
}
