import { Head, Link, setLayoutProps } from '@inertiajs/react';
import DeliverableStatusBadge from '@/components/deliverable-status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { show as deliverableShow } from '@/routes/deliverables';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as deliverablesIndex } from '@/routes/engagements/deliverables';
import { acceptancePack } from '@/routes/engagements/milestones';
import type {
    DeliverableListItem,
    EngagementPositionSummary,
    EngagementStatus,
    MilestoneSummary,
    Money,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    baselineVersion: number | null;
    deliverables: DeliverableListItem[];
    milestones: MilestoneSummary[];
    accepted: { count: number; total: number; value: Money };
    position: EngagementPositionSummary;
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

function ProgressBar({ progress }: { progress: number }) {
    return (
        <div className="flex items-center gap-2">
            <div className="h-[6px] w-20 border border-ink/40 dark:border-paper/40">
                <div
                    className="h-full bg-ink dark:bg-paper"
                    style={{ width: `${progress}%` }}
                />
            </div>
            <span className="font-plex-mono text-[12px]">{progress}%</span>
        </div>
    );
}

function DeliverableTable({
    deliverables,
}: {
    deliverables: DeliverableListItem[];
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-[13px]">
                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                    <tr>
                        <th className={tableHeading}>Deliverable</th>
                        <th className={tableHeading}>Status</th>
                        <th className={tableHeading}>Progress</th>
                        <th className={tableHeading}>Evidence</th>
                        <th className={tableHeading}>Forecast</th>
                        <th className={tableHeading}>Value</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                    {deliverables.map((deliverable) => (
                        <tr
                            key={deliverable.id}
                            data-test={`deliverable-row-${deliverable.id}`}
                        >
                            <td className="px-4 py-2">
                                <div className="flex flex-col gap-1">
                                    <Link
                                        href={deliverableShow(deliverable.id)}
                                        prefetch
                                        className="font-medium hover:text-rust"
                                    >
                                        {deliverable.title}
                                    </Link>
                                    <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                        {deliverable.ownerName ?? 'No owner'} ·{' '}
                                        {deliverable.confidenceLabel} confidence
                                    </span>
                                    {deliverable.respondByOverdue && (
                                        <span className="w-fit border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap text-rust uppercase">
                                            Response overdue
                                        </span>
                                    )}
                                </div>
                            </td>
                            <td className="px-4 py-2">
                                <DeliverableStatusBadge
                                    status={deliverable.status}
                                    label={deliverable.statusLabel}
                                />
                                {deliverable.acceptedAt !== null && (
                                    <div className="mt-1 font-plex-mono text-[11px] text-stone dark:text-fog">
                                        signed {deliverable.acceptedAt}
                                    </div>
                                )}
                            </td>
                            <td className="px-4 py-2">
                                <ProgressBar progress={deliverable.progress} />
                            </td>
                            <td className="px-4 py-2 font-plex-mono">
                                <span
                                    className={cn(
                                        deliverable.criteriaCount > 0 &&
                                            deliverable.evidencedCriteriaCount <
                                                deliverable.criteriaCount &&
                                            'text-ochre',
                                    )}
                                >
                                    {deliverable.evidencedCriteriaCount}/
                                    {deliverable.criteriaCount}
                                </span>
                                <span className="text-stone dark:text-fog">
                                    {' '}
                                    criteria
                                </span>
                            </td>
                            <td className="px-4 py-2 font-plex-mono">
                                {deliverable.forecastDate ?? '—'}
                            </td>
                            <td className="px-4 py-2 font-plex-mono font-semibold">
                                {deliverable.value?.formatted ?? '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function EngagementDeliverables({
    engagement,
    baselineVersion,
    deliverables,
    milestones,
    accepted,
    position,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            {
                title: 'Deliverables',
                href: deliverablesIndex(engagement.id),
            },
        ],
        position,
    });

    const awaiting = deliverables.filter(
        (deliverable) => deliverable.status === 'awaiting_acceptance',
    ).length;
    const rejected = deliverables.filter(
        (deliverable) => deliverable.status === 'rejected',
    ).length;

    const stats = [
        {
            label: 'Accepted',
            value: `${accepted.count}/${accepted.total}`,
            warn: false,
        },
        {
            label: 'Awaiting signature',
            value: String(awaiting),
            warn: awaiting > 0,
        },
        { label: 'Rejected', value: String(rejected), warn: rejected > 0 },
    ];

    const unassigned = deliverables.filter(
        (deliverable) => deliverable.milestoneItemId === null,
    );

    return (
        <>
            <Head title={`${engagement.name} — Deliverables`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Deliverables &amp; acceptance
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Every contracted deliverable carries its own
                            evidence-backed record. Submitting freezes it for
                            customer review; accepted always means signed, and
                            the signed value accrues to your position.
                        </p>
                    </div>
                    <div
                        className="flex gap-3"
                        data-test="deliverables-summary"
                    >
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

                {deliverables.length === 0 ? (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            {baselineVersion === null
                                ? 'Deliverable records appear once a baseline is approved — they are provisioned from its deliverable items.'
                                : 'This baseline carries no deliverable items.'}
                        </p>
                    </div>
                ) : (
                    <>
                        {milestones.map((milestone) => {
                            const grouped = deliverables.filter(
                                (deliverable) =>
                                    deliverable.milestoneItemId ===
                                    milestone.id,
                            );

                            return (
                                <div
                                    key={milestone.id}
                                    className="border-[1.5px] border-ink dark:border-paper"
                                    data-test={`milestone-group-${milestone.id}`}
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                        <div className="flex flex-col">
                                            <span className={sectionLabel}>
                                                Milestone · {milestone.title}
                                            </span>
                                            <span className="mt-0.5 font-plex-mono text-[11px] text-stone dark:text-fog">
                                                {milestone.baselineDate ??
                                                    'No date'}
                                                {milestone.paymentTrigger !==
                                                    null &&
                                                    ` · ${milestone.paymentTrigger}`}
                                            </span>
                                        </div>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                            data-test={`open-acceptance-pack-${milestone.id}`}
                                        >
                                            <Link
                                                href={acceptancePack([
                                                    engagement.id,
                                                    milestone.id,
                                                ])}
                                                prefetch
                                            >
                                                Acceptance pack →
                                            </Link>
                                        </Button>
                                    </div>
                                    {grouped.length === 0 ? (
                                        <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                                            No deliverables assigned to this
                                            milestone yet.
                                        </p>
                                    ) : (
                                        <DeliverableTable
                                            deliverables={grouped}
                                        />
                                    )}
                                </div>
                            );
                        })}

                        {unassigned.length > 0 && (
                            <div className="border-[1.5px] border-ink dark:border-paper">
                                <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                    <span className={sectionLabel}>
                                        Unassigned to a milestone
                                    </span>
                                </div>
                                <DeliverableTable deliverables={unassigned} />
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
