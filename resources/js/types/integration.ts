import type { Money } from './domain';

export type IntegrationProvider = 'jira' | 'linear';

export type IntegrationConnectionStatus = 'connected' | 'disconnected';

export type SyncRunStatus = 'running' | 'succeeded' | 'failed';

export type WorkItemSource = 'jira' | 'linear' | 'manual';

export type WorkItemState = 'todo' | 'in_progress' | 'done' | 'canceled';

export type SyncRunView = {
    id: string;
    status: SyncRunStatus;
    statusLabel: string;
    startedAt: string;
    counts: Record<string, number> | null;
    error: string | null;
};

export type IntegrationAccountOption = {
    id: string;
    provider: IntegrationProvider;
    providerLabel: string;
    name: string;
};

export type IntegrationConnectionView = {
    id: string;
    provider: IntegrationProvider;
    providerLabel: string;
    status: IntegrationConnectionStatus;
    statusLabel: string;
    externalProjectKey: string;
    accountName: string | null;
    connectedByName: string | null;
    connectedAt: string | null;
    disconnectedAt: string | null;
    lastSyncedAt: string | null;
    runs: SyncRunView[];
};

export type WorkItemLinkView = {
    deliverableId: string;
    deliverableTitle: string;
    linkedByName: string | null;
    linkedAt: string | null;
};

export type WorkItemView = {
    id: string;
    source: WorkItemSource;
    sourceLabel: string;
    externalKey: string | null;
    externalUrl: string | null;
    title: string;
    state: WorkItemState;
    stateLabel: string;
    externalStatus: string | null;
    type: string | null;
    assigneeName: string | null;
    estimate: string | null;
    logged: string | null;
    link: WorkItemLinkView | null;
};

export type ReleaseView = {
    id: string;
    sourceLabel: string;
    name: string;
    released: boolean;
    releasedOn: string | null;
    externalUrl: string | null;
};

export type WorkMappingSummary = {
    total: number;
    linked: number;
    unlinked: number;
};

export type WorkItemTriageStatus =
    'existing_scope' | 'potential_change' | 'operational' | 'dismissed';

export type TriageInboxItemView = {
    id: string;
    title: string;
    externalKey: string | null;
    externalUrl: string | null;
    sourceLabel: string;
    type: string | null;
    assigneeName: string | null;
    state: WorkItemState;
    stateLabel: string;
    externalStatus: string | null;
    ageDays: number;
    firstSeen: string | null;
    estimate: string | null;
    logged: string | null;
    effortDays: number | null;
    cost: Money | null;
    price: Money | null;
    workStartedAt: string | null;
    breachRisk: boolean;
    suggestedDeliverable: { id: string; title: string } | null;
    timelineImpact: {
        milestone: string;
        daysUntil: number | null;
        effortDays: number;
    } | null;
};

export type TriagedItemView = {
    id: string;
    title: string;
    externalKey: string | null;
    sourceLabel: string;
    classification: WorkItemTriageStatus;
    classificationLabel: string;
    triagedByName: string | null;
    triagedAt: string | null;
    note: string | null;
    deliverableTitle: string | null;
    changeRequest: {
        id: string;
        title: string;
        statusLabel: string;
        breachRisk: boolean;
    } | null;
};

export type TriagePricingView = {
    /** Whether the viewer may see commercial figures (rate card policy). */
    visible: boolean;
    available: boolean;
    baselineVersion: number | null;
    rateCardVersion: number | null;
    costPerDay: Money | null;
    sellPerDay: Money | null;
    hoursPerDay: number;
};

export type EngagementWorkSummary = {
    itemCount: number;
    unlinkedCount: number;
    connections: {
        providerLabel: string;
        status: IntegrationConnectionStatus;
        statusLabel: string;
        lastSyncedAt: string | null;
    }[];
};
