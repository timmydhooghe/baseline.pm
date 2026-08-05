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
