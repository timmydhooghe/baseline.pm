<?php

namespace App\Enums;

/**
 * The typed items a contract is decomposed into on a baseline (FA-5 step 3).
 * Every item traces back to a contract clause.
 */
enum BaselineItemType: string
{
    case Deliverable = 'deliverable';
    case Milestone = 'milestone';
    case Assumption = 'assumption';
    case Exclusion = 'exclusion';
    case Responsibility = 'responsibility';

    public function label(): string
    {
        return match ($this) {
            self::Deliverable => 'Deliverable',
            self::Milestone => 'Milestone',
            self::Assumption => 'Assumption',
            self::Exclusion => 'Exclusion',
            self::Responsibility => 'Customer responsibility',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Deliverable => 'Deliverables',
            self::Milestone => 'Milestones',
            self::Assumption => 'Assumptions',
            self::Exclusion => 'Exclusions',
            self::Responsibility => 'Customer responsibilities',
        };
    }
}
