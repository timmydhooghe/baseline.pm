<?php

namespace App\Enums;

/**
 * The change request lifecycle (FA-11): draft → under assessment → customer
 * proposal → awaiting approval → approved/rejected. Drift triage only
 * creates drafts (FA-9); the transitions and their rules arrive with change
 * control.
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
}
