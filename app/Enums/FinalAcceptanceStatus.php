<?php

namespace App\Enums;

/**
 * The engagement-level final acceptance gate (FA-24): submitted as a frozen
 * record awaiting the customer's signature. Acceptance completes the
 * engagement; rejection and clarification return it to Active for rework and
 * a fresh submission; withdrawal closes the request internally. Every
 * outcome except awaiting-response is terminal for the record — a new
 * submission is a new record.
 */
enum FinalAcceptanceStatus: string
{
    case AwaitingResponse = 'awaiting_response';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case ClarificationRequested = 'clarification_requested';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingResponse => 'Awaiting response',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::ClarificationRequested => 'Clarification requested',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * Whether the record still awaits the customer's decision.
     */
    public function isOpen(): bool
    {
        return $this === self::AwaitingResponse;
    }
}
