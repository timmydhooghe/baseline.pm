import type { Money } from './domain';

export type ChangeRequestStatus =
    | 'draft'
    | 'under_assessment'
    | 'customer_proposal'
    | 'awaiting_approval'
    | 'approved'
    | 'rejected';

export type ChangeRequestOrigin =
    'drift' | 'steering_call' | 'email' | 'meeting' | 'other';

export type ChangeRequestDecision =
    'approved' | 'rejected' | 'clarification_requested';

export type ChangeRequestListItem = {
    id: string;
    title: string;
    status: ChangeRequestStatus;
    statusLabel: string;
    originLabel: string | null;
    breachRisk: boolean;
    price: Money | null;
    estimatedDays: number | null;
    respondBy: string | null;
    respondByOverdue: boolean;
    decidedAt: string | null;
    mintedBaselineVersion: number | null;
    createdAt: string | null;
    workItemKey: string | null;
};

export type ChangeRequestView = {
    id: string;
    title: string;
    what: string;
    why: string | null;
    origin: ChangeRequestOrigin | null;
    originLabel: string | null;
    status: ChangeRequestStatus;
    statusLabel: string;
    estimatedDays: number | null;
    loggedHours: number | null;
    workStartedAt: string | null;
    breachRisk: boolean;
    workItem: {
        id: string;
        title: string;
        externalKey: string | null;
        externalUrl: string | null;
    } | null;
    customerPrice: Money | null;
    impactMilestoneId: string | null;
    impactDays: number | null;
    scopeAdded: string | null;
    scopeRemoved: string | null;
    alternatives: string | null;
    rateCardVersion: number | null;
    submittedAt: string | null;
    respondBy: string | null;
    respondByOverdue: boolean;
    decidedAt: string | null;
    mintedBaselineVersion: number | null;
    createdByName: string | null;
    createdAt: string | null;
};

export type ChangeRequestAllocationView = {
    id: string;
    rateCardRoleId: string;
    roleName: string;
    days: string;
    costPerDay: Money;
    cost: Money;
};

export type ChangeRequestAssessmentView = {
    allocations: ChangeRequestAllocationView[];
    affectedItemIds: string[];
    cost: Money | null;
    suggestedPrice: Money | null;
    margin: Money | null;
    marginPercent: number | null;
};

export type ChangeRequestRoleOption = {
    id: string;
    name: string;
    costPerDay: Money;
    sellPerDay: Money;
};

export type ChangeRequestBaselineItem = {
    id: string;
    type: string;
    typeLabel: string;
    title: string;
    baselineDate: string | null;
};

export type ChangeRequestResponseView = {
    id: string;
    decision: ChangeRequestDecision;
    decisionLabel: string;
    stakeholderName: string;
    comment: string | null;
    respondedAt: string;
};

/**
 * The frozen customer-visible snapshot a stakeholder reviews in the portal
 * (FA-13): price, scope and schedule — cost and margin are never present.
 */
export type ChangeRequestProposalPayload = {
    kind: string;
    change_request: {
        id: string;
        title: string;
        what: string;
        why: string | null;
        origin: string | null;
        engagement: { id: string; name: string };
        customer: { id: string; name: string };
    };
    price: Money | null;
    scope: {
        added: string | null;
        removed: string | null;
        alternatives: string | null;
    };
    affected_items: { id: string; type: string; title: string }[];
    schedule_impact: {
        milestone: { id: string; title: string };
        baseline_date: string | null;
        days: number | null;
        projected_date: string | null;
    } | null;
    respond_by: string | null;
};
