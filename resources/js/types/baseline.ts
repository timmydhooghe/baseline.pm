import type { Money, SelectOption } from '@/types';

export type BaselineStatus = 'draft' | 'awaiting_approval' | 'approved';

export type BaselineItemType =
    'deliverable' | 'milestone' | 'assumption' | 'exclusion' | 'responsibility';

export type AcceptanceCriterion = {
    criterion: string;
    verificationMethod: string | null;
};

export type BaselineItemView = {
    id: string;
    type: BaselineItemType;
    position: number;
    title: string;
    description: string | null;
    clauseReference: string;
    owner: { id: string; name: string } | null;
    value: Money | null;
    acceptanceCriteria: AcceptanceCriterion[];
    baselineDate: string | null;
    paymentTrigger: string | null;
};

export type BaselineDocumentView = {
    id: string;
    filename: string;
    sizeBytes: number;
    uploadedAt: string | null;
    uploadedBy: string | null;
};

export type BaselineAllocationView = {
    id: string;
    baselineItemId: string | null;
    rateCardRoleId: string;
    days: string;
};

export type CompletenessCheckView = {
    key: string;
    label: string;
    passed: boolean;
    detail: string;
    acknowledged: boolean;
    acknowledgedBy: string | null;
    acknowledgedAt: string | null;
};

export type BaselineRateCardRoleView = {
    id: string;
    name: string;
    costPerDay: Money;
    sellPerDay: Money;
};

export type BaselineRateCardView = {
    version: number;
    roles: BaselineRateCardRoleView[];
};

export type BaselineView = {
    id: string;
    version: number;
    status: BaselineStatus;
    statusLabel: string;
    commercialModel: string;
    contractValue: Money;
    startDate: string;
    endDate: string;
    executionMode: string;
    submittedAt: string | null;
    approvedAt: string | null;
    documents: BaselineDocumentView[];
    items: BaselineItemView[];
    allocations: BaselineAllocationView[];
    totals: {
        costBudget: Money;
        deliveryManagementCost: Money;
        plannedMargin: Money;
        deliverableBudgets: Record<string, { direct: Money; budget: Money }>;
    } | null;
    checks: CompletenessCheckView[];
    canSubmit: boolean;
};

export type BaselineMemberOption = {
    id: string;
    name: string;
};

export type BaselineWizardOptions = {
    members: BaselineMemberOption[];
    commercialModels: SelectOption[];
    executionModes: SelectOption[];
};
