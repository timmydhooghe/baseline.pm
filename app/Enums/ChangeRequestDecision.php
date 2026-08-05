<?php

namespace App\Enums;

/**
 * A customer's immutable response to a submitted change request (FA-13).
 * Approval mints the next baseline version, rejection is terminal, and a
 * clarification request returns the change request to assessment.
 */
enum ChangeRequestDecision: string
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
