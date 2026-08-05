<?php

namespace App\Enums;

/**
 * A customer's immutable response to a submitted acceptance review (FA-23,
 * FA-24): a deliverable review or the engagement's final acceptance.
 * Acceptance is a signature — never assumed, never internal. Rejection sends
 * the work back for rework and a clarification request reopens it without a
 * verdict; both stay on record.
 */
enum AcceptanceDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case ClarificationRequested = 'clarification_requested';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::ClarificationRequested => 'Clarification requested',
        };
    }
}
