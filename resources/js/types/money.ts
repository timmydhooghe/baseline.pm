import type { Money, SelectOption } from './domain';
import type { RecordChip } from './governance';

export type BurnSource = 'worklog' | 'progress' | 'manual';

/**
 * One line of a week, before or after it is recorded. `roleId` is null on a
 * worklog line whose person has never been booked against a profile — the
 * days are known, the rate is not, and the manager picks it once.
 */
export type BurnLine = {
    roleId: string | null;
    roleName: string | null;
    personName: string | null;
    userId: string | null;
    days: number;
    source: BurnSource;
    sourceLabel: string;
    costPerDay: Money | null;
    cost: Money | null;
    /** Where the number came from, in words. */
    basis: string;
};

export type BurnRoleOption = SelectOption & { costPerDay: Money };

/**
 * The week the entry form opens on: prefilled from worklogs and progress, or
 * read back from the recording when the week is already on the ledger.
 */
export type BurnWeekForm = {
    weekStart: string;
    weekLabel: string;
    recorded: boolean;
    recordedAt: string | null;
    recordedByName: string | null;
    /** Weighted deliverable progress the suggestions derive from, 0–1. */
    weightedProgress: number | null;
    loggedHours: number;
    lines: BurnLine[];
    roles: BurnRoleOption[];
};

export type BurnEntryView = {
    id: string;
    roleName: string;
    personName: string | null;
    attributedTo: string;
    days: number;
    source: BurnSource;
    sourceLabel: string;
    costPerDay: Money;
    cost: Money;
};

export type BurnWeekView = {
    id: string;
    weekStart: string;
    weekLabel: string;
    cost: Money;
    days: number;
    note: string | null;
    recordedAt: string;
    recordedByName: string | null;
    supersededAt: string | null;
    entries: BurnEntryView[];
};

/** A recorded week plus the recordings it replaced — corrections are entries. */
export type BurnLedgerWeek = BurnWeekView & { corrects: BurnWeekView[] };

export type UnrecordedWeek = { weekStart: string; weekLabel: string };

export type BurnSummary = {
    recordedBurn: Money;
    recordedDays: number;
    costBudget: Money | null;
    budgetPercent: number | null;
    forecastCost: Money | null;
    margin: Money | null;
    marginPercent: number | null;
    weekCount: number;
    hasBaseline: boolean;
};

/**
 * The margin derivation (FA-15). Everything but recorded burn is absent until
 * a baseline is approved — there is no commitment to forecast against yet.
 */
export type MarginForecastView = {
    hasBaseline: boolean;
    baselineVersion: number | null;
    rateCardVersion: number | null;
    approvedRevenue: Money | null;
    costBudget: Money | null;
    plannedMargin: Money | null;
    plannedMarginPercent: number | null;
    recordedBurn: Money;
    recordedDays: number;
    remainingCost: Money | null;
    remainingDays: number | null;
    forecastCost: Money | null;
    margin: Money | null;
    marginPercent: number | null;
    budgetPercent: number | null;
    forecastPercent: number | null;
    variance: Money | null;
    weekCount: number;
    lastRecordedWeek: string | null;
    unrecordedWeeks: number;
};

/** Planned against recorded, per profile — where the forecast comes from. */
export type MarginRoleRow = {
    name: string;
    costPerDay: Money;
    plannedDays: number;
    plannedCost: Money;
    recordedDays: number;
    recordedCost: Money;
    remainingDays: number;
    remainingCost: Money;
    overrunDays: number;
    unplanned: boolean;
};

/**
 * One cause in the "why it moved" decomposition. The causes plus the
 * reconciling `unattributed` line always sum to the variance against plan.
 */
export type MarginAttribution = {
    key:
        | 'absorbed_scope_creep'
        | 'dependency_delay'
        | 'risk_materialised'
        | 'staffing_premium'
        | 'unattributed';
    label: string;
    detail: string;
    amount: Money;
    records: RecordChip[];
    moreCount: number;
};

export type MarginRiskBand = {
    liveRisks: number;
    exposure: Money;
    weightedExposure: Money;
    low: Money | null;
    lowPercent: number | null;
};
