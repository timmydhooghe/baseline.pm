import type { Money } from './domain';

type TodayEngagementReference = {
    engagementId: string;
    engagementName: string;
};

export type TodayScopeCreepRow = TodayEngagementReference & {
    count: number;
    unpriced: number;
    /** Absent for viewers without rate card access — sell-rate-derived. */
    price: Money | null;
};

export type TodayChangeRequestRow = TodayEngagementReference & {
    id: string;
    title: string;
    price: Money | null;
    respondBy: string | null;
    overdue: boolean;
};

export type TodayLateDependencyRow = TodayEngagementReference & {
    id: string;
    title: string;
    responsible: string | null;
    party: string;
    partyLabel: string;
    requiredOn: string;
    delayDays: number;
    impact: {
        record: { type: string; type_label: string; id: string; title: string };
        baseline_date: string | null;
        projected_date: string | null;
    }[];
    impactCount: number;
};

export type TodayEscalatedRiskRow = TodayEngagementReference & {
    id: string;
    title: string;
    rating: string;
    worsening: boolean;
    /** Absent for viewers without rate card access — cost-derived. */
    exposure: Money | null;
};

export type TodayUnrecordedBurnRow = TodayEngagementReference & {
    count: number;
    oldestWeekStart: string;
    oldestWeekLabel: string;
};

export type TodayReportDraftRow = TodayEngagementReference & {
    count: number;
    latestWeekStart: string;
    latestWeekLabel: string;
};

export type TodaySections = {
    scopeCreep: TodayScopeCreepRow[];
    changeRequests: TodayChangeRequestRow[];
    lateDependencies: TodayLateDependencyRow[];
    escalatedRisks: TodayEscalatedRiskRow[];
    unrecordedBurn: TodayUnrecordedBurnRow[];
    reportDrafts: TodayReportDraftRow[];
};

export type TodayQuietRow = {
    id: string;
    name: string;
    customerName: string;
    statusLabel: string;
    line: string;
};

export type TodayMilestone = TodayEngagementReference & {
    id: string;
    title: string;
    date: string | null;
    dateLabel: string | null;
    overdue: boolean;
    openDeliverables: number;
};

export type TodayCustomerAction = TodayEngagementReference & {
    kind: 'dependency' | 'change_request' | 'deliverable' | 'final_acceptance';
    id: string;
    title: string;
    responsible: string | null;
    due: string | null;
    dueLabel: string | null;
    overdue: boolean;
};
