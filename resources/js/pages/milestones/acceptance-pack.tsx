import { Head, Link, setLayoutProps } from '@inertiajs/react';
import DeliverableStatusBadge from '@/components/deliverable-status-badge';
import { cn } from '@/lib/utils';
import { show as deliverableShow } from '@/routes/deliverables';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as deliverablesIndex } from '@/routes/engagements/deliverables';
import { acceptancePack } from '@/routes/engagements/milestones';
import type {
    DeliverableStatus,
    EngagementPositionSummary,
    Money,
    RecordVisibility,
} from '@/types';

type PackDeliverable = {
    id: string;
    title: string;
    clauseReference: string;
    ownerName: string | null;
    value: Money | null;
    status: DeliverableStatus;
    statusLabel: string;
    acceptedAt: string | null;
    acceptedValue: Money | null;
    acceptedBy: string | null;
    acceptanceComment: string | null;
    evidence: {
        id: string;
        kindLabel: string;
        label: string;
        url: string | null;
        visibility: RecordVisibility;
    }[];
};

type Props = {
    engagement: { id: string; name: string };
    milestone: {
        id: string;
        title: string;
        baselineDate: string | null;
        paymentTrigger: string | null;
        clauseReference: string;
    };
    deliverables: PackDeliverable[];
    totals: {
        count: number;
        acceptedCount: number;
        value: Money;
        acceptedValue: Money;
    };
    complete: boolean;
    position: EngagementPositionSummary;
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export default function MilestoneAcceptancePack({
    engagement,
    milestone,
    deliverables,
    totals,
    complete,
    position,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Deliverables', href: deliverablesIndex(engagement.id) },
            {
                title: `${milestone.title} pack`,
                href: acceptancePack([engagement.id, milestone.id]),
            },
        ],
        position,
    });

    return (
        <>
            <Head title={`${milestone.title} — Acceptance pack`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Milestone acceptance pack
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {milestone.title}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Assembled from the signed acceptances of this
                            milestone's deliverables — who signed what, when, at
                            what value, on what evidence.
                        </p>
                        <p className="mt-1 font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                            {milestone.clauseReference} ·{' '}
                            {milestone.baselineDate ?? 'No date'}
                            {milestone.paymentTrigger !== null &&
                                ` · ${milestone.paymentTrigger}`}
                        </p>
                    </div>
                    <div className="flex gap-3" data-test="pack-totals">
                        <div className="border-[1.5px] border-ink px-3 py-2 dark:border-paper">
                            <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                Signed
                            </div>
                            <div className="font-plex-mono text-[20px] font-semibold">
                                {totals.acceptedCount}/{totals.count}
                            </div>
                        </div>
                        <div
                            className={cn(
                                'border-[1.5px] px-3 py-2',
                                complete
                                    ? 'border-moss text-moss'
                                    : 'border-ink dark:border-paper',
                            )}
                        >
                            <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                Accepted value
                            </div>
                            <div className="font-plex-mono text-[20px] font-semibold">
                                {totals.acceptedValue.formatted}
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    className={cn(
                        'border-[1.5px] px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase',
                        complete
                            ? 'border-moss text-moss'
                            : 'border-ink bg-sun/40 dark:border-paper',
                    )}
                    data-test="pack-state"
                >
                    {complete
                        ? `Pack complete — every deliverable signed, ${totals.acceptedValue.formatted} of ${totals.value.formatted} contracted.`
                        : `Pack incomplete — ${totals.count - totals.acceptedCount} of ${totals.count} deliverables still await a signature.`}
                </div>

                {deliverables.length === 0 ? (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            No deliverables are assigned to this milestone yet.
                            Assign them on each deliverable record.
                        </p>
                    </div>
                ) : (
                    deliverables.map((deliverable) => (
                        <div
                            key={deliverable.id}
                            className="border-[1.5px] border-ink dark:border-paper"
                            data-test={`pack-deliverable-${deliverable.id}`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                <div className="flex flex-col">
                                    <Link
                                        href={deliverableShow(deliverable.id)}
                                        prefetch
                                        className="text-[15px] font-semibold hover:text-rust"
                                    >
                                        {deliverable.title}
                                    </Link>
                                    <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                        {deliverable.clauseReference} ·{' '}
                                        {deliverable.ownerName ?? 'No owner'}
                                    </span>
                                </div>
                                <div className="flex items-center gap-3">
                                    <DeliverableStatusBadge
                                        status={deliverable.status}
                                        label={deliverable.statusLabel}
                                    />
                                    <span className="font-plex-mono text-[16px] font-semibold">
                                        {deliverable.acceptedValue?.formatted ??
                                            deliverable.value?.formatted ??
                                            '—'}
                                    </span>
                                </div>
                            </div>
                            <div className="flex flex-col gap-3 px-4 py-3 text-[13px]">
                                {deliverable.acceptedBy === null ? (
                                    <p className="text-stone dark:text-fog">
                                        No signature on record yet.
                                    </p>
                                ) : (
                                    <p>
                                        Signed by{' '}
                                        <span className="font-semibold">
                                            {deliverable.acceptedBy}
                                        </span>
                                        {deliverable.acceptedAt !== null &&
                                            ` on ${deliverable.acceptedAt}`}
                                        {deliverable.acceptanceComment !==
                                            null && (
                                            <span className="text-stone dark:text-fog">
                                                {' '}
                                                — “
                                                {deliverable.acceptanceComment}”
                                            </span>
                                        )}
                                    </p>
                                )}
                                {deliverable.evidence.length > 0 && (
                                    <div>
                                        <span className={sectionLabel}>
                                            Evidence
                                        </span>
                                        <ul className="mt-1 flex flex-wrap gap-1.5">
                                            {deliverable.evidence.map(
                                                (evidence) => (
                                                    <li
                                                        key={evidence.id}
                                                        className={cn(
                                                            'border px-2 py-0.5 text-[12px]',
                                                            evidence.visibility ===
                                                                'shared'
                                                                ? 'border-moss'
                                                                : 'border-ink/40 text-stone dark:border-paper/40 dark:text-fog',
                                                        )}
                                                    >
                                                        <span className="font-plex-mono text-[10px] font-semibold uppercase">
                                                            {evidence.kindLabel}
                                                        </span>{' '}
                                                        {evidence.url ===
                                                        null ? (
                                                            evidence.label
                                                        ) : (
                                                            <a
                                                                href={
                                                                    evidence.url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="underline hover:text-rust"
                                                            >
                                                                {evidence.label}
                                                            </a>
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </>
    );
}
