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

export type PlanUsage = {
    activeCount: number;
    limit: number | null;
};
