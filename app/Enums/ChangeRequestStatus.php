<?php

namespace App\Enums;

/**
 * The change request lifecycle (FA-11): draft → under assessment → customer
 * proposal → awaiting approval → approved/rejected. A clarification request
 * returns the change request to assessment; approval mints the next baseline
 * version. Approved and rejected are terminal — the decision is on record.
 */
enum ChangeRequestStatus: string
{
    case Draft = 'draft';
    case UnderAssessment = 'under_assessment';
    case CustomerProposal = 'customer_proposal';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::UnderAssessment => 'Under assessment',
            self::CustomerProposal => 'Customer proposal',
            self::AwaitingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * A proposal can move back to assessment — internally before submission,
     * or through a customer clarification request while awaiting approval.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::UnderAssessment],
            self::UnderAssessment => [self::CustomerProposal],
            self::CustomerProposal => [self::AwaitingApproval, self::UnderAssessment],
            self::AwaitingApproval => [self::Approved, self::Rejected, self::UnderAssessment],
            self::Approved, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the customer has decided — terminal either way.
     */
    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }

    /**
     * Whether the structured assessment (role mix, affected items, schedule
     * impact, commercial terms) is open for editing.
     */
    public function acceptsAssessment(): bool
    {
        return in_array($this, [self::UnderAssessment, self::CustomerProposal], true);
    }
}
