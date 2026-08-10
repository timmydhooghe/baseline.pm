import type { EngagementPositionSummary, Money } from './domain';
import type { RecordChip } from './governance';

/**
 * A record chip inside a report payload. Published payloads are frozen, so
 * the link is resolved at render time by the server and rides alongside the
 * chip — internal readers get it, the portal never does.
 */
export type ReportRecordChip = RecordChip & { href?: string | null };

export type ReportMovedLine = {
    record: ReportRecordChip;
    status: string;
    status_label: string;
    progress: number;
    value: Money | null;
    forecast_date: string | null;
    milestone: string | null;
    accepted_at: string | null;
    previous: {
        progress: number | null;
        status: string | null;
        status_label: string | null;
    } | null;
};

export type ReportChangedLine = {
    record: ReportRecordChip;
    event: string;
    event_label: string;
    date: string;
    detail: string | null;
};

export type ReportOwedLine = {
    record: ReportRecordChip;
    party: string;
    party_label: string;
    responsible: string | null;
    required_on: string;
    late: boolean;
    delay_days: number;
    status: string;
    status_label: string;
};

export type ReportCommercials = {
    position: EngagementPositionSummary;
    burn_week: { cost: Money; days: number } | null;
    previous: {
        margin_percent: number | null;
        recorded_burn: Money | null;
    } | null;
};

export type ReportPayload = {
    kind: 'internal_report' | 'customer_report';
    week: { start: string; end: string; label: string };
    engagement: { id: string; name: string };
    customer: { id: string; name: string };
    baseline: { version: number; contract_value: Money } | null;
    previous: {
        report_id: string;
        week_start: string;
        week_label: string;
    } | null;
    moved: ReportMovedLine[];
    changed: ReportChangedLine[];
    owed: ReportOwedLine[];
    commercials?: ReportCommercials;
};

export type ReportMeta = {
    published: boolean;
    weekStart: string;
    weekLabel: string;
    publishedAt: string | null;
    publishedByName: string | null;
};

export type ReportListItem = {
    id: string;
    weekStart: string;
    weekLabel: string;
    publishedAt: string;
    publishedByName: string | null;
};

export type ReportDueWeek = {
    weekStart: string;
    weekLabel: string;
};
