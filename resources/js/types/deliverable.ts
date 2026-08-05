import type { Money } from './domain';

export type DeliverableStatus =
    'in_progress' | 'awaiting_acceptance' | 'accepted' | 'rejected';

export type AcceptanceDecision =
    'accepted' | 'rejected' | 'clarification_requested';

export type DeliverableConfidence = 'high' | 'medium' | 'low';

export type EvidenceKind =
    'release' | 'demo' | 'test_report' | 'document' | 'other';

export type RecordVisibility = 'internal' | 'shared';

export type FinalAcceptanceStatus =
    | 'awaiting_response'
    | 'accepted'
    | 'rejected'
    | 'clarification_requested'
    | 'withdrawn';

export type DeliverableListItem = {
    id: string;
    title: string;
    ownerName: string | null;
    value: Money | null;
    status: DeliverableStatus;
    statusLabel: string;
    progress: number;
    confidence: DeliverableConfidence;
    confidenceLabel: string;
    forecastDate: string | null;
    milestoneItemId: string | null;
    respondBy: string | null;
    respondByOverdue: boolean;
    acceptedAt: string | null;
    acceptedValue: Money | null;
    criteriaCount: number;
    evidencedCriteriaCount: number;
    evidenceCount: number;
};

export type DeliverableView = {
    id: string;
    title: string;
    description: string | null;
    clauseReference: string;
    baselineVersion: number;
    ownerName: string | null;
    value: Money | null;
    status: DeliverableStatus;
    statusLabel: string;
    progress: number;
    confidence: DeliverableConfidence;
    forecastDate: string | null;
    milestoneItemId: string | null;
    submittedAt: string | null;
    respondBy: string | null;
    respondByOverdue: boolean;
    decidedAt: string | null;
    acceptedAt: string | null;
    acceptedValue: Money | null;
};

export type DeliverableCriterionView = {
    criterion: string;
    verificationMethod: string | null;
    evidenceId: string | null;
    visibility: RecordVisibility;
};

export type DeliverableEvidenceView = {
    id: string;
    kind: EvidenceKind;
    kindLabel: string;
    label: string;
    url: string | null;
    visibility: RecordVisibility;
    visibilityLabel: string;
    addedByName: string | null;
    addedAt: string | null;
};

export type DeliverableVersionView = {
    id: string;
    baselineVersion: number;
    value: Money | null;
    recordedAt: string;
};

export type DeliverableLinkedWorkView = {
    id: string;
    title: string;
    externalKey: string | null;
    externalUrl: string | null;
    stateLabel: string;
    classification: string | null;
    classificationLabel: string | null;
};

export type DeliverableResponseView = {
    id: string;
    decision: AcceptanceDecision;
    decisionLabel: string;
    stakeholderName: string;
    comment: string | null;
    respondedAt: string;
};

export type DeliverableMilestoneOption = {
    id: string;
    title: string;
    baselineDate: string | null;
};

export type MilestoneSummary = {
    id: string;
    title: string;
    baselineDate: string | null;
    paymentTrigger: string | null;
};

export type FinalAcceptanceSummary = {
    id: string;
    status: FinalAcceptanceStatus;
    statusLabel: string;
    submittedAt: string | null;
    respondBy: string | null;
    decidedAt: string | null;
    decidedBy: string | null;
    comment: string | null;
};

export type EngagementAcceptanceSummary = {
    total: number;
    accepted: number;
    awaiting: number;
    acceptedValue: Money;
    finalAcceptance: FinalAcceptanceSummary | null;
};

/**
 * The frozen customer-visible snapshot a stakeholder reviews in the portal
 * (FA-23): the contractual record, progress and shared evidence — cost,
 * margin and confidence are never present.
 */
export type DeliverableReviewPayload = {
    kind: string;
    deliverable: {
        id: string;
        title: string;
        description: string | null;
        clause_reference: string;
        baseline_version: number;
        engagement: { id: string; name: string };
        customer: { id: string; name: string };
    };
    value: Money | null;
    progress: number;
    forecast_date: string | null;
    milestone: {
        id: string;
        title: string;
        baseline_date: string | null;
    } | null;
    acceptance_criteria: {
        criterion: string;
        verification_method: string | null;
        evidence: { kind: string; label: string; url: string | null } | null;
    }[];
    evidence: { kind: string; label: string; url: string | null }[];
    respond_by: string | null;
};

/**
 * The frozen customer-visible snapshot of an engagement's final acceptance
 * (FA-24): every signed deliverable acceptance and the accepted total.
 */
export type FinalAcceptanceReviewPayload = {
    kind: string;
    engagement: {
        id: string;
        name: string;
        customer: { id: string; name: string };
    };
    baseline_version: number | null;
    contract_value: Money | null;
    accepted_value: Money;
    deliverables: {
        id: string;
        title: string;
        value: Money | null;
        accepted_on: string | null;
        accepted_by: string | null;
    }[];
    respond_by: string | null;
};
