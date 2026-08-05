<?php

namespace App\Enums;

/**
 * The classification a drift item received in the triage inbox (FA-9).
 * Untriaged drift carries no status at all — a null column — and keeps
 * counting toward unbilled risk (FA-10). Every classification is recorded
 * with classifier and timestamp, and even dismissals stay on record.
 */
enum WorkItemTriageStatus: string
{
    case ExistingScope = 'existing_scope';
    case PotentialChange = 'potential_change';
    case Operational = 'operational';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::ExistingScope => 'Existing scope',
            self::PotentialChange => 'Potential change',
            self::Operational => 'Operational',
            self::Dismissed => 'Dismissed',
        };
    }
}
