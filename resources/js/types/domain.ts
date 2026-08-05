export type EngagementStatus =
    | 'draft'
    | 'preparing_baseline'
    | 'awaiting_baseline_approval'
    | 'active'
    | 'awaiting_final_acceptance'
    | 'completed'
    | 'archived';

export type StakeholderRole = 'project_manager' | 'approver' | 'viewer';

export type SelectOption = {
    value: string;
    label: string;
};

export type Money = {
    amount: number;
    currency: string;
    formatted: string;
};

export type PlanUsage = {
    activeCount: number;
    limit: number | null;
};

export type EngagementPositionSummary = {
    engagementId: string;
    contracted: Money | null;
    baselineVersion: number | null;
    accepted: {
        count: number;
        total: number;
        value: Money;
    };
    unbilledRisk: {
        count: number;
        unpriced: number;
        price: Money;
    };
};
