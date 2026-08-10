<?php

namespace App\Enums;

/**
 * A customer's immutable response to a submitted baseline (FA-5 step 6,
 * FA-27). Approval turns the draft into the engagement's committed version;
 * rejection and clarification requests both return it to draft — the review
 * snapshots stay on record either way.
 */
enum BaselineDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ClarificationRequested = 'clarification_requested';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::ClarificationRequested => 'Clarification requested',
        };
    }
}
