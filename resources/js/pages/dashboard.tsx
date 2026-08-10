import type { UrlMethodPair } from '@inertiajs/core';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as changeRequestShow } from '@/routes/change-requests';
import { show as deliverableShow } from '@/routes/deliverables';
import { show as dependencyShow } from '@/routes/dependencies';
import { show as engagementShow } from '@/routes/engagements';
import { index as burnIndex } from '@/routes/engagements/burn';
import { acceptancePack } from '@/routes/engagements/milestones';
import { draft as reportDraft } from '@/routes/engagements/reports';
import { show as triageShow } from '@/routes/engagements/triage';
import { show as riskShow } from '@/routes/risks';
import type {
    TodayCustomerAction,
    TodayMilestone,
    TodayQuietRow,
    TodaySections,
} from '@/types';

type Props = {
    sections: TodaySections;
    quiet: TodayQuietRow[];
    milestones: TodayMilestone[];
    customerActions: TodayCustomerAction[];
    engagementCount: number;
    can: { viewCommercials: boolean; manageGovernance: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const engagementTag =
    'font-plex-mono text-[11px] text-stone uppercase dark:text-fog';

const actionKindLabels: Record<TodayCustomerAction['kind'], string> = {
    dependency: 'Dependency',
    change_request: 'Change request',
    deliverable: 'Deliverable review',
    final_acceptance: 'Final acceptance',
};

/**
 * Today (FA-25): only what needs attention. Exceptions cross the dashboard —
 * scope creep with its € at risk, change requests awaiting decision, late
 * dependencies, escalated risks, unrecorded burn weeks, unpublished report
 * drafts — while quiet engagements get one line each, and the rail keeps the
 * calendar: milestones and what the customer owes.
 */
export default function Dashboard({
    sections,
    quiet,
    milestones,
    customerActions,
    engagementCount,
}: Props) {
    const exceptionCount =
        sections.scopeCreep.length +
        sections.changeRequests.length +
        sections.lateDependencies.length +
        sections.escalatedRisks.length +
        sections.unrecordedBurn.length +
        sections.reportDrafts.length;

    const stats = [
        {
            label: 'Needs attention',
            value: String(exceptionCount),
            warn: exceptionCount > 0,
        },
        {
            label: 'Customer actions',
            value: String(customerActions.length),
            warn: customerActions.some((action) => action.overdue),
        },
        {
            label: 'Running engagements',
            value: String(engagementCount),
            warn: false,
        },
    ];

    return (
        <>
            <Head title="Today" />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Today
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            What needs attention
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Exceptions only — everything below is a record that
                            needs a decision, a chase or an entry. Engagements
                            with nothing to flag stay to one line.
                        </p>
                    </div>
                    <div className="flex gap-3" data-test="today-summary">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={
                                    stat.warn
                                        ? 'border-[1.5px] border-rust px-3 py-2 text-rust'
                                        : 'border-[1.5px] border-ink px-3 py-2 dark:border-paper'
                                }
                            >
                                <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                    {stat.label}
                                </div>
                                <div className="font-plex-mono text-[20px] font-semibold">
                                    {stat.value}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="flex flex-col gap-6">
                        {exceptionCount === 0 && (
                            <div
                                className="border-[1.5px] border-moss px-4 py-6 text-center"
                                data-test="today-all-clear"
                            >
                                <div className="font-plex-mono text-[11px] font-semibold text-moss uppercase">
                                    All clear
                                </div>
                                <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                                    No exceptions across the portfolio — every
                                    queue is empty and every register is quiet.
                                </p>
                            </div>
                        )}

                        <ExceptionSection
                            title="Scope creep backlog"
                            count={sections.scopeCreep.length}
                            testId="today-scope-creep"
                        >
                            {sections.scopeCreep.map((row) => (
                                <ExceptionRow
                                    key={row.engagementId}
                                    href={triageShow(row.engagementId)}
                                    title={`${row.count} unmapped ${row.count === 1 ? 'item' : 'items'} awaiting triage`}
                                    engagementName={row.engagementName}
                                    meta={
                                        row.price !== null
                                            ? `${row.price.formatted} at risk${row.unpriced > 0 ? ` · ${row.unpriced} unpriced` : ''}`
                                            : row.unpriced > 0
                                              ? `${row.unpriced} unpriced`
                                              : null
                                    }
                                    warn
                                />
                            ))}
                        </ExceptionSection>

                        <ExceptionSection
                            title="Change requests awaiting decision"
                            count={sections.changeRequests.length}
                            testId="today-change-requests"
                        >
                            {sections.changeRequests.map((row) => (
                                <ExceptionRow
                                    key={row.id}
                                    href={changeRequestShow(row.id)}
                                    title={row.title}
                                    engagementName={row.engagementName}
                                    meta={[
                                        row.price?.formatted,
                                        row.respondBy !== null
                                            ? `respond by ${row.respondBy}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    warn={row.overdue}
                                    warnLabel={
                                        row.overdue ? 'Deadline passed' : null
                                    }
                                />
                            ))}
                        </ExceptionSection>

                        <ExceptionSection
                            title="Late dependencies"
                            count={sections.lateDependencies.length}
                            testId="today-late-dependencies"
                        >
                            {sections.lateDependencies.map((row) => (
                                <ExceptionRow
                                    key={row.id}
                                    href={dependencyShow(row.id)}
                                    title={row.title}
                                    engagementName={row.engagementName}
                                    meta={[
                                        row.responsible !== null
                                            ? `owed by ${row.responsible}`
                                            : row.partyLabel,
                                        `${row.delayDays} d late`,
                                        row.impactCount > 0
                                            ? `pushes ${row.impact[0]?.record.title ?? ''}${row.impactCount > 1 ? ` +${row.impactCount - 1}` : ''}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    warn
                                />
                            ))}
                        </ExceptionSection>

                        <ExceptionSection
                            title="Escalated risks"
                            count={sections.escalatedRisks.length}
                            testId="today-escalated-risks"
                        >
                            {sections.escalatedRisks.map((row) => (
                                <ExceptionRow
                                    key={row.id}
                                    href={riskShow(row.id)}
                                    title={row.title}
                                    engagementName={row.engagementName}
                                    meta={[
                                        row.rating,
                                        row.worsening ? 'worsening' : null,
                                        row.exposure !== null
                                            ? `${row.exposure.formatted} exposed`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    warn
                                />
                            ))}
                        </ExceptionSection>

                        <ExceptionSection
                            title="Unrecorded burn weeks"
                            count={sections.unrecordedBurn.length}
                            testId="today-unrecorded-burn"
                        >
                            {sections.unrecordedBurn.map((row) => (
                                <ExceptionRow
                                    key={row.engagementId}
                                    href={burnIndex(row.engagementId)}
                                    title={`${row.count} ${row.count === 1 ? 'week' : 'weeks'} without a recording`}
                                    engagementName={row.engagementName}
                                    meta={`oldest: ${row.oldestWeekLabel}`}
                                    warn
                                />
                            ))}
                        </ExceptionSection>

                        <ExceptionSection
                            title="Report drafts"
                            count={sections.reportDrafts.length}
                            testId="today-report-drafts"
                        >
                            {sections.reportDrafts.map((row) => (
                                <ExceptionRow
                                    key={row.engagementId}
                                    href={reportDraft([
                                        row.engagementId,
                                        row.latestWeekStart,
                                    ])}
                                    title={`${row.count} ${row.count === 1 ? 'week' : 'weeks'} awaiting publication`}
                                    engagementName={row.engagementName}
                                    meta={`latest: ${row.latestWeekLabel}`}
                                />
                            ))}
                        </ExceptionSection>

                        {quiet.length > 0 && (
                            <div
                                className="border-[1.5px] border-ink dark:border-paper"
                                data-test="today-quiet"
                            >
                                <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                    <span className={sectionLabel}>
                                        Quiet · {quiet.length}
                                    </span>
                                </div>
                                <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {quiet.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2 text-[13px]"
                                            data-test={`quiet-${row.id}`}
                                        >
                                            <Link
                                                href={engagementShow(row.id)}
                                                prefetch
                                                className="font-medium hover:text-rust"
                                            >
                                                {row.name}
                                            </Link>
                                            <span className={engagementTag}>
                                                {row.customerName}
                                            </span>
                                            <span className="text-stone dark:text-fog">
                                                {row.line}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    <div className="flex flex-col gap-6">
                        <div
                            className="border-[1.5px] border-ink dark:border-paper"
                            data-test="today-milestones"
                        >
                            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                <span className={sectionLabel}>Milestones</span>
                            </div>
                            {milestones.length === 0 ? (
                                <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                                    Nothing dated ahead.
                                </p>
                            ) : (
                                <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {milestones.map((milestone) => (
                                        <li
                                            key={milestone.id}
                                            className="px-4 py-2 text-[13px]"
                                        >
                                            <Link
                                                href={acceptancePack([
                                                    milestone.engagementId,
                                                    milestone.id,
                                                ])}
                                                prefetch
                                                className="font-medium hover:text-rust"
                                            >
                                                {milestone.title}
                                            </Link>
                                            <span
                                                className={cn(
                                                    'block font-plex-mono text-[11px] uppercase',
                                                    milestone.overdue
                                                        ? 'font-semibold text-rust'
                                                        : 'text-stone dark:text-fog',
                                                )}
                                            >
                                                {milestone.dateLabel}
                                                {milestone.overdue &&
                                                    ` · ${milestone.openDeliverables} open`}
                                            </span>
                                            <span className={engagementTag}>
                                                {milestone.engagementName}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div
                            className="border-[1.5px] border-ink dark:border-paper"
                            data-test="today-customer-actions"
                        >
                            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                <span className={sectionLabel}>
                                    Customer actions
                                </span>
                            </div>
                            {customerActions.length === 0 ? (
                                <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                                    The customer owes nothing right now.
                                </p>
                            ) : (
                                <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {customerActions.map((action) => (
                                        <li
                                            key={`${action.kind}-${action.id}`}
                                            className="px-4 py-2 text-[13px]"
                                        >
                                            <Link
                                                href={customerActionHref(
                                                    action,
                                                )}
                                                prefetch
                                                className="font-medium hover:text-rust"
                                            >
                                                {action.title}
                                            </Link>
                                            <span className="block font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                {actionKindLabels[action.kind]}
                                                {action.responsible !== null &&
                                                    ` · ${action.responsible}`}
                                            </span>
                                            {action.dueLabel !== null && (
                                                <span
                                                    className={cn(
                                                        'font-plex-mono text-[11px] uppercase',
                                                        action.overdue
                                                            ? 'font-semibold text-rust'
                                                            : 'text-stone dark:text-fog',
                                                    )}
                                                >
                                                    {action.overdue
                                                        ? `overdue since ${action.dueLabel}`
                                                        : `due ${action.dueLabel}`}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

function customerActionHref(action: TodayCustomerAction) {
    switch (action.kind) {
        case 'dependency':
            return dependencyShow(action.id);
        case 'change_request':
            return changeRequestShow(action.id);
        case 'deliverable':
            return deliverableShow(action.id);
        case 'final_acceptance':
            return engagementShow(action.engagementId);
    }
}

function ExceptionSection({
    title,
    count,
    testId,
    children,
}: {
    title: string;
    count: number;
    testId: string;
    children: ReactNode;
}) {
    if (count === 0) {
        return null;
    }

    return (
        <div
            className="border-[1.5px] border-ink dark:border-paper"
            data-test={testId}
        >
            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className={sectionLabel}>
                    {title} · {count}
                </span>
            </div>
            <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                {children}
            </ul>
        </div>
    );
}

function ExceptionRow({
    href,
    title,
    engagementName,
    meta,
    warn = false,
    warnLabel = null,
}: {
    href: string | UrlMethodPair;
    title: string;
    engagementName: string;
    meta?: string | null;
    warn?: boolean;
    warnLabel?: string | null;
}) {
    return (
        <li className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2 text-[13px]">
            <Link href={href} prefetch className="font-medium hover:text-rust">
                {title}
            </Link>
            <span className={engagementTag}>{engagementName}</span>
            {meta != null && meta !== '' && (
                <span
                    className={cn(
                        'font-plex-mono text-[12px]',
                        warn
                            ? 'font-semibold text-rust'
                            : 'text-stone dark:text-fog',
                    )}
                >
                    {meta}
                </span>
            )}
            {warnLabel !== null && (
                <span className="border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold text-rust uppercase">
                    {warnLabel}
                </span>
            )}
        </li>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Today',
            href: dashboard(),
        },
    ],
};
