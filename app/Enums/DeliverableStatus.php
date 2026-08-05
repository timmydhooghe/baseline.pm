<?php

namespace App\Enums;

/**
 * The deliverable acceptance lifecycle (FA-23): in progress → submitted for
 * acceptance on a frozen review snapshot → accepted (signed) or rejected.
 * Rejection is not terminal — the deliverable is reworked and resubmitted
 * with fresh snapshots; a clarification request reopens it without a verdict.
 * Accepted is terminal: the signature is on record.
 */
enum DeliverableStatus: string
{
    case InProgress = 'in_progress';
    case AwaitingAcceptance = 'awaiting_acceptance';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::AwaitingAcceptance => 'Awaiting acceptance',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::InProgress => [self::AwaitingAcceptance],
            self::AwaitingAcceptance => [self::Accepted, self::Rejected, self::InProgress],
            self::Rejected => [self::AwaitingAcceptance],
            self::Accepted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the execution record (progress, confidence, forecast,
     * evidence, criteria) is open for editing: a submitted deliverable is
     * frozen for review and an accepted one is signed history.
     */
    public function acceptsUpdates(): bool
    {
        return in_array($this, [self::InProgress, self::Rejected], true);
    }
}
