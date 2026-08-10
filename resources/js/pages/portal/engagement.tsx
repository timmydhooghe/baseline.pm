import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import type {
    EngagementStatus,
    Money,
    PortalAction,
    PortalBaselineSummary,
    PortalChangeRequestRow,
    PortalDecisionRow,
    PortalDependencyRow,
    PortalMilestone,
    PortalReportRow,
    PortalRiskRow,
    PortalScopeDeliverable,
    PortalStakeholderView,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    customer: { name: string };
    organization: { name: string };
    stakeholder: PortalStakeholderView & { canApprove: boolean };
    baseline: PortalBaselineSummary | null;
    actions: PortalAction[];
    scope: {
        deliverables: PortalScopeDeliverable[];
        acceptedValue: Money;
    };
    milestones: PortalMilestone[];
    changeRequests: PortalChangeRequestRow[];
    decisions: PortalDecisionRow[];
    risks: PortalRiskRow[];
    dependencies: PortalDependencyRow[];
    reports: PortalReportRow[];
};

const actionTypeLabels: Record<PortalAction['type'], string> = {
    baseline: 'Baseline',
    change_request: 'Change request',
    deliverable: 'Deliverable',
    final_acceptance: 'Final acceptance',
};

/**
 * One engagement as the customer sees it (FA-27): a cover sheet of the
 * committed baseline, then ledgers of shared records — decisions awaited,
 * items owed, scope and progress, milestones, changes, decisions, risks and
 * published reports. Review links are personally signed for the signed-in
 * stakeholder; viewers see the state of play without doors they cannot open.
 */
export default function PortalEngagement({
    engagement,
    customer,
    organization,
    stakeholder,
    baseline,
    actions,
    scope,
    milestones,
    changeRequests,
    decisions,
    risks,
    dependencies,
    reports,
}: Props) {
    return (
        <>
            <Head title={engagement.name} />
            <PortalLayout
                eyebrow={`Engagement · ${organization.name}`}
                title={engagement.name}
                intro={`Everything ${organization.name} shares with ${customer.name}: the committed scope, its progress, and what needs you.`}
                session={{
                    stakeholderName: stakeholder.name,
                    customerName: customer.name,
                }}
                wide
            >
                {/* Cover sheet: the commitments this engagement runs against. */}
                <div className="grid border-[1.5px] border-ink sm:grid-cols-4">
                    <CoverFact label="Status" value={engagement.statusLabel} />
                    <CoverFact
                        label="Baseline"
                        value={
                            baseline === null
                                ? 'Awaiting approval'
                                : `v${baseline.version}`
                        }
                        detail={baseline?.approvedAt ?? undefined}
                    />
                    <CoverFact
                        label="Contract value"
                        value={baseline?.contractValue.formatted ?? '—'}
                        detail={baseline?.commercialModel}
                        mono
                        testId="portal-contract-value"
                    />
                    <CoverFact
                        label="Timeline"
                        value={
                            baseline === null
                                ? '—'
                                : `${baseline.startDate} → ${baseline.endDate}`
                        }
                        last
                    />
                </div>

                {actions.length > 0 && (
                    <Section
                        label="Awaiting your decision"
                        count={actions.length}
                        highlighted
                    >
                        <ul className="divide-y divide-ink/15">
                            {actions.map((action, index) => (
                                <li
                                    key={`${action.type}-${index}`}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"
                                    data-test="portal-action"
                                >
                                    <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase">
                                        {actionTypeLabels[action.type]}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-[14px] font-semibold">
                                            {action.title}
                                        </span>
                                        <span className="block text-[12px] text-stone">
                                            {action.description}
                                        </span>
                                    </span>
                                    {action.respondBy !== null && (
                                        <span
                                            className={cn(
                                                'font-plex-mono text-[12px]',
                                                action.overdue
                                                    ? 'font-semibold text-rust'
                                                    : 'text-stone',
                                            )}
                                        >
                                            {action.overdue
                                                ? `Overdue since ${action.respondBy}`
                                                : `Respond by ${action.respondBy}`}
                                        </span>
                                    )}
                                    {action.url === null ? (
                                        <span className="font-plex-mono text-[11px] text-stone uppercase">
                                            An approver on your side decides
                                        </span>
                                    ) : (
                                        <Link
                                            href={action.url}
                                            className="border-[1.5px] border-ink px-3 py-1.5 font-plex-mono text-[11px] font-semibold uppercase transition-colors hover:bg-ink hover:text-paper"
                                            data-test="portal-action-review"
                                        >
                                            Review &amp; respond →
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {dependencies.length > 0 && (
                    <Section
                        label={`Owed by ${customer.name}`}
                        count={dependencies.length}
                    >
                        <ul className="divide-y divide-ink/15">
                            {dependencies.map((dependency) => (
                                <li
                                    key={dependency.id}
                                    className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                                    data-test="portal-owed"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-[14px] font-semibold">
                                            {dependency.title}
                                        </span>
                                        {dependency.description !== null && (
                                            <span className="block text-[12px] text-stone">
                                                {dependency.description}
                                            </span>
                                        )}
                                    </span>
                                    {dependency.responsible !== null && (
                                        <span className="text-[12px] text-stone">
                                            {dependency.responsible}
                                        </span>
                                    )}
                                    <span
                                        className={cn(
                                            'font-plex-mono text-[12px]',
                                            dependency.late
                                                ? 'font-semibold text-rust'
                                                : 'text-stone',
                                        )}
                                    >
                                        {dependency.late
                                            ? `${dependency.delayDays}d late — needed ${dependency.requiredOn}`
                                            : `Needed by ${dependency.requiredOn}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Section label="Scope & progress">
                    {scope.deliverables.length === 0 ? (
                        <Empty>
                            Deliverables appear here once the baseline is
                            approved.
                        </Empty>
                    ) : (
                        <>
                            <ul className="divide-y divide-ink/15">
                                {scope.deliverables.map((deliverable) => (
                                    <li
                                        key={deliverable.id}
                                        className="flex flex-col gap-2 px-4 py-3"
                                        data-test="portal-deliverable"
                                    >
                                        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                            <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                                {deliverable.title}
                                            </span>
                                            {deliverable.milestone !== null && (
                                                <span className="text-[12px] text-stone">
                                                    {deliverable.milestone}
                                                </span>
                                            )}
                                            <span className="font-plex-mono text-[13px] font-semibold">
                                                {deliverable.value?.formatted ??
                                                    '—'}
                                            </span>
                                            <StatusChip
                                                status={deliverable.status}
                                                label={deliverable.statusLabel}
                                            />
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <div className="h-1 flex-1 bg-ink/10">
                                                <div
                                                    className={cn(
                                                        'h-full',
                                                        deliverable.status ===
                                                            'accepted'
                                                            ? 'bg-moss'
                                                            : 'bg-ink',
                                                    )}
                                                    style={{
                                                        width: `${deliverable.progress}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="font-plex-mono text-[11px] text-stone">
                                                {deliverable.progress}%
                                            </span>
                                            <span className="font-plex-mono text-[11px] text-stone">
                                                {deliverable.acceptedAt !== null
                                                    ? `Signed ${deliverable.acceptedAt}`
                                                    : deliverable.forecastDate !==
                                                        null
                                                      ? `Forecast ${deliverable.forecastDate}`
                                                      : ''}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <div className="flex flex-wrap items-baseline justify-between gap-2 border-t-[1.5px] border-ink px-4 py-3">
                                <span className={portalSectionLabel}>
                                    Accepted — signed by your side
                                </span>
                                <span
                                    className="font-plex-mono text-[15px] font-bold"
                                    data-test="portal-accepted-value"
                                >
                                    {scope.acceptedValue.formatted}
                                    {baseline !== null && (
                                        <span className="font-normal text-stone">
                                            {' '}
                                            of{' '}
                                            {baseline.contractValue.formatted}
                                        </span>
                                    )}
                                </span>
                            </div>
                        </>
                    )}
                </Section>

                <Section label="Milestones">
                    {milestones.length === 0 ? (
                        <Empty>This baseline carries no milestones.</Empty>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {milestones.map((milestone) => (
                                <li
                                    key={milestone.id}
                                    className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                                    data-test="portal-milestone"
                                >
                                    <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                        {milestone.title}
                                    </span>
                                    {milestone.paymentTrigger !== null && (
                                        <span className="text-[12px] text-stone">
                                            {milestone.paymentTrigger}
                                        </span>
                                    )}
                                    {milestone.deliverables.total > 0 && (
                                        <span
                                            className={cn(
                                                'font-plex-mono text-[12px]',
                                                milestone.deliverables
                                                    .accepted ===
                                                    milestone.deliverables.total
                                                    ? 'font-semibold text-moss'
                                                    : 'text-stone',
                                            )}
                                        >
                                            {milestone.deliverables.accepted}/
                                            {milestone.deliverables.total}{' '}
                                            signed
                                        </span>
                                    )}
                                    <span className="font-plex-mono text-[12px]">
                                        {milestone.baselineDate ?? '—'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section label="Changes to the agreement">
                    {changeRequests.length === 0 ? (
                        <Empty>
                            No changes have been proposed — the baseline stands
                            as approved.
                        </Empty>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {changeRequests.map((changeRequest) => (
                                <li
                                    key={changeRequest.id}
                                    className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                                    data-test="portal-change-request"
                                >
                                    <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                        {changeRequest.title}
                                    </span>
                                    <span className="font-plex-mono text-[13px] font-semibold">
                                        {changeRequest.price?.formatted ?? '—'}
                                    </span>
                                    <span className="font-plex-mono text-[12px] text-stone">
                                        {changeRequest.decidedAt ??
                                            changeRequest.submittedAt ??
                                            ''}
                                    </span>
                                    <StatusChip
                                        status={changeRequest.status}
                                        label={changeRequest.statusLabel}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section label="Decisions on record">
                    {decisions.length === 0 ? (
                        <Empty>No shared decisions yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {decisions.map((decision) => (
                                <li
                                    key={decision.id}
                                    className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                                    data-test="portal-decision"
                                >
                                    <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                        {decision.title}
                                    </span>
                                    <span className="font-plex-mono text-[12px] text-stone">
                                        {decision.decidedOn ?? ''}
                                    </span>
                                    {decision.acknowledgedAt === null ? (
                                        <Link
                                            href={decision.url}
                                            className="font-plex-mono text-[11px] font-semibold uppercase underline-offset-4 hover:text-rust hover:underline"
                                        >
                                            Read &amp; acknowledge →
                                        </Link>
                                    ) : (
                                        <span className="font-plex-mono text-[11px] text-moss uppercase">
                                            Acknowledged{' '}
                                            {decision.acknowledgedAt}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section label="Shared risks">
                    {risks.length === 0 ? (
                        <Empty>No shared risks are live right now.</Empty>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {risks.map((risk) => (
                                <li
                                    key={risk.id}
                                    className="flex flex-col gap-1 px-4 py-3"
                                    data-test="portal-risk"
                                >
                                    <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                        <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                            {risk.title}
                                        </span>
                                        <span className="font-plex-mono text-[12px] text-stone">
                                            {risk.probability} probability ×{' '}
                                            {risk.impact} impact
                                        </span>
                                        <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase">
                                            {risk.statusLabel}
                                        </span>
                                    </div>
                                    {risk.mitigation !== null && (
                                        <p className="text-[12px] text-stone">
                                            Mitigation: {risk.mitigation}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section label="Weekly reports">
                    {reports.length === 0 ? (
                        <Empty>No reports have been published yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {reports.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                                    data-test="portal-report"
                                >
                                    <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                        {report.label}
                                    </span>
                                    <span className="font-plex-mono text-[12px] text-stone">
                                        Published {report.publishedAt}
                                    </span>
                                    {report.url !== null && (
                                        <Link
                                            href={report.url}
                                            className="font-plex-mono text-[11px] font-semibold uppercase underline-offset-4 hover:text-rust hover:underline"
                                        >
                                            Read →
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </PortalLayout>
        </>
    );
}

function CoverFact({
    label,
    value,
    detail,
    mono = false,
    last = false,
    testId,
}: {
    label: string;
    value: string;
    detail?: string;
    mono?: boolean;
    last?: boolean;
    testId?: string;
}) {
    return (
        <div
            className={cn(
                'border-ink/20 px-4 py-3',
                !last && 'sm:border-r',
                'border-b-[1.5px] border-b-ink/20 sm:border-b-0',
            )}
        >
            <div className={portalSectionLabel}>{label}</div>
            <div
                className={cn(
                    'mt-1 text-[14px] font-semibold',
                    mono && 'font-plex-mono text-[15px] font-bold',
                )}
                data-test={testId}
            >
                {value}
            </div>
            {detail !== undefined && (
                <div className="text-[11px] text-stone">{detail}</div>
            )}
        </div>
    );
}

function Section({
    label,
    count,
    highlighted = false,
    children,
}: {
    label: string;
    count?: number;
    highlighted?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="border-[1.5px] border-ink">
            <div
                className={cn(
                    'flex items-baseline justify-between border-b-[1.5px] border-ink px-4 py-3',
                    highlighted && 'bg-sun/40',
                )}
            >
                <span className={portalSectionLabel}>{label}</span>
                {count !== undefined && (
                    <span className="font-plex-mono text-[12px] font-bold">
                        {count}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="px-4 py-4 text-[13px] text-stone">{children}</p>;
}

function StatusChip({ status, label }: { status: string; label: string }) {
    return (
        <span
            className={cn(
                'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase',
                ['accepted', 'approved'].includes(status) &&
                    'border-moss text-moss',
                status === 'rejected' && 'border-rust text-rust',
                ['awaiting_acceptance', 'awaiting_approval'].includes(status) &&
                    'border-ochre text-ochre',
                ![
                    'accepted',
                    'approved',
                    'rejected',
                    'awaiting_acceptance',
                    'awaiting_approval',
                ].includes(status) && 'border-ink/40',
            )}
        >
            {label}
        </span>
    );
}
