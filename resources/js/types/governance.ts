import type { RecordVisibility } from './deliverable';
import type { Money, SelectOption } from './domain';

export type DecisionStatus = 'draft' | 'confirmed' | 'superseded';

export type DecisionSource = 'manual' | 'transcript';

export type RiskRating = 'low' | 'medium' | 'high';

export type RiskStatus = 'open' | 'mitigating' | 'closed' | 'materialised';

export type DependencyParty = 'customer' | 'internal';

export type DependencyStatus =
    'pending' | 'requested' | 'escalated' | 'received' | 'waived';

export type DependencyEventType =
    'requested' | 'reminded' | 'escalated' | 'received' | 'waived' | 'note';

/**
 * A linked governance record as the ledgers render it — the morph class and
 * key travel back unchanged when the link is saved.
 */
export type RecordChip = {
    type: string;
    type_label: string;
    id: string;
    title: string;
};

export type DecisionAlternative = {
    option: string;
    why_not: string | null;
};

export type DecisionParticipant = {
    name: string;
    affiliation: string | null;
};

export type DecisionEvidence = {
    label: string;
    url: string | null;
};

export type DecisionListItem = {
    id: string;
    title: string;
    decision: string | null;
    status: DecisionStatus;
    statusLabel: string;
    source: DecisionSource;
    sourceLabel: string;
    visibility: RecordVisibility;
    visibilityLabel: string;
    decidedOn: string | null;
    decidedOnDate: string | null;
    decidedById: string | null;
    decidedByName: string | null;
    impactScope: string | null;
    impactTimelineDays: number | null;
    participantCount: number;
    links: RecordChip[];
    supersedesId: string | null;
    supersedesTitle: string | null;
    supersededById: string | null;
    supersededByTitle: string | null;
    acknowledgedAt: string | null;
    acknowledgedByName: string | null;
    recordedAt: string | null;
};

export type DecisionView = DecisionListItem & {
    context: string;
    alternatives: DecisionAlternative[];
    participants: DecisionParticipant[];
    evidence: DecisionEvidence[];
    impact: {
        scope: string | null;
        budget: Money | null;
        timelineDays: number | null;
    };
    transcriptExcerpt: string | null;
    createdByName: string | null;
    acknowledgementComment: string | null;
};

export type DecisionChainEntry = {
    id: string;
    title: string;
    decidedOn: string | null;
    statusLabel: string;
};

export type GovernanceOptions = {
    records: RecordChip[];
    members: SelectOption[];
    supersedable?: SelectOption[];
    stakeholders?: SelectOption[];
    roles?: RiskRoleOption[];
};

export type RiskRoleOption = {
    value: string;
    label: string;
    costPerDay: Money;
};

export type RiskListItem = {
    id: string;
    title: string;
    probability: RiskRating;
    probabilityLabel: string;
    impact: RiskRating;
    impactLabel: string;
    score: number;
    status: RiskStatus;
    statusLabel: string;
    ownerName: string | null;
    ownerId: string | null;
    visibility: RecordVisibility;
    visibilityLabel: string;
    escalated: boolean;
    worsening: boolean;
    /** Absent for viewers without rate card access — cost-derived. */
    exposure: Money | null;
    weightedExposure: Money | null;
    exposureLineCount: number;
    links: RecordChip[];
    raisedAt: string | null;
};

export type RiskView = RiskListItem & {
    description: string | null;
    mitigation: string | null;
    createdByName: string | null;
    rateCardVersion: number | null;
    closedAt: string | null;
};

export type RiskExposureLine = {
    id: string;
    roleId: string;
    roleName: string;
    days: number;
    costPerDay: Money;
    cost: Money;
};

export type RiskRevisionView = {
    id: string;
    probability: RiskRating;
    probabilityLabel: string;
    impact: RiskRating;
    impactLabel: string;
    score: number;
    status: RiskStatus;
    statusLabel: string;
    weightedExposure: Money | null;
    note: string | null;
    actorName: string | null;
    recordedAt: string;
};

export type DependencyListItem = {
    id: string;
    title: string;
    party: DependencyParty;
    partyLabel: string;
    status: DependencyStatus;
    statusLabel: string;
    responsibleName: string | null;
    /** An outstanding item whose responsible person was removed. */
    needsReassignment: boolean;
    responsibleStakeholderId: string | null;
    responsibleUserId: string | null;
    requiredOn: string;
    requiredOnDate: string;
    settledOn: string | null;
    delayDays: number;
    late: boolean;
    attribution: DependencyParty;
    attributionLabel: string;
    visibility: RecordVisibility;
    visibilityLabel: string;
    links: RecordChip[];
    eventCount: number;
};

export type DependencyView = DependencyListItem & {
    description: string | null;
    createdByName: string | null;
    escalatedAt: string | null;
};

export type DependencyImpact = {
    record: RecordChip;
    baseline_date: string | null;
    projected_date: string | null;
};

export type DependencyEventView = {
    id: string;
    type: DependencyEventType;
    typeLabel: string;
    channel: string | null;
    note: string | null;
    evidenceUrl: string | null;
    actorName: string | null;
    occurredAt: string;
};

export type AuditEntry = {
    id: string;
    action: string;
    subjectType: string;
    subjectId: string;
    actorName: string | null;
    payload: Record<string, unknown> | null;
    recordedAt: string;
};

/**
 * The frozen customer-visible payload of a shared decision (FA-18): the
 * record, its alternatives and its scope and timeline impact. Budget impact
 * is never present — the portal carries no money the customer did not agree
 * to see.
 */
export type DecisionAcknowledgementPayload = {
    kind: string;
    decision: {
        id: string;
        title: string;
        context: string;
        decision: string | null;
        decided_on: string | null;
        engagement: { id: string; name: string };
        customer: { id: string; name: string };
    };
    alternatives: DecisionAlternative[];
    participants: DecisionParticipant[];
    evidence: DecisionEvidence[];
    impact: { scope: string | null; timeline_days: number | null };
    linked_records: RecordChip[];
};
