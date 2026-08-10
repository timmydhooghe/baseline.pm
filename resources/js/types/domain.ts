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

/**
 * The live commercial waterfall (FA-14, FA-15). `burn` and `margin` are
 * cost-derived and structurally absent for viewers without rate card access;
 * the contract figures above them are not stripped.
 */
export type EngagementPositionSummary = {
    engagementId: string;
    contracted: Money | null;
    baselineVersion: number | null;
    accepted: {
        count: number;
        total: number;
        value: Money;
    };
    pendingChange: {
        count: number;
        /** Requests in flight that carry no price yet — counted, never guessed. */
        unpriced: number;
        price: Money;
    };
    unbilledRisk: {
        count: number;
        unpriced: number;
        /** Absent for viewers without rate card access — sell-rate-derived. */
        price: Money | null;
    };
    burn: {
        recorded: Money;
        costBudget: Money | null;
        budgetPercent: number | null;
        forecastPercent: number | null;
        weeks: number;
        unrecordedWeeks: number;
    } | null;
    margin: {
        forecast: Money;
        percent: number | null;
        planned: Money;
        plannedPercent: number | null;
        variance: Money;
        /** The bottom of the risk band: margin less probability-weighted exposure. */
        low: Money;
        lowPercent: number | null;
        weightedExposure: Money;
    } | null;
};
