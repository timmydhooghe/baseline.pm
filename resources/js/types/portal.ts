import type {
    ChangeRequestStatus,
    DeliverableStatus,
    EngagementStatus,
    Money,
} from '@/types';

/**
 * The signed-in stakeholder's portal (FA-27). Everything here is a shared
 * record projected for the customer — no cost, rate or margin field exists
 * in these shapes, by construction.
 */
export type PortalStakeholderView = {
    name: string;
    roleLabel: string;
};

export type PortalEngagementCard = {
    id: string;
    name: string;
    status: EngagementStatus;
    statusLabel: string;
    baselineVersion: number | null;
    awaitingCount: number;
    owedCount: number;
    lastReport: string | null;
};

export type PortalActionType =
    'baseline' | 'change_request' | 'deliverable' | 'final_acceptance';

export type PortalAction = {
    type: PortalActionType;
    title: string;
    description: string;
    respondBy: string | null;
    overdue: boolean;
    /** Personally signed review link; null when the role cannot respond. */
    url: string | null;
};

export type PortalBaselineSummary = {
    version: number;
    commercialModel: string;
    contractValue: Money;
    startDate: string;
    endDate: string;
    approvedAt: string | null;
};

export type PortalScopeDeliverable = {
    id: string;
    title: string;
    description: string | null;
    status: DeliverableStatus;
    statusLabel: string;
    progress: number;
    forecastDate: string | null;
    milestone: string | null;
    value: Money | null;
    acceptedAt: string | null;
};

export type PortalMilestone = {
    id: string;
    title: string;
    baselineDate: string | null;
    paymentTrigger: string | null;
    deliverables: { accepted: number; total: number };
};

export type PortalChangeRequestRow = {
    id: string;
    title: string;
    status: ChangeRequestStatus;
    statusLabel: string;
    price: Money | null;
    submittedAt: string | null;
    decidedAt: string | null;
};

export type PortalDecisionRow = {
    id: string;
    title: string;
    statusLabel: string;
    decidedOn: string | null;
    acknowledgedAt: string | null;
    acknowledgedBy: string | null;
    url: string;
};

export type PortalRiskRow = {
    id: string;
    title: string;
    description: string | null;
    probability: string;
    impact: string;
    statusLabel: string;
    mitigation: string | null;
};

export type PortalDependencyRow = {
    id: string;
    title: string;
    description: string | null;
    responsible: string | null;
    requiredOn: string;
    late: boolean;
    delayDays: number;
    statusLabel: string;
};

export type PortalReportRow = {
    id: string;
    label: string;
    publishedAt: string;
    url: string | null;
};
