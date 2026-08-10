import { Head, Link, setLayoutProps } from '@inertiajs/react';
import BurnWeekForm from '@/components/burn-week-form';
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as burnIndex } from '@/routes/engagements/burn';
import { show as marginShow } from '@/routes/engagements/margin';
import type {
    BurnLedgerWeek,
    BurnSummary,
    BurnWeekForm as BurnWeekFormData,
    BurnWeekView,
    EngagementPositionSummary,
    EngagementStatus,
    UnrecordedWeek,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    week: BurnWeekFormData;
    weeks: BurnLedgerWeek[];
    unrecorded: UnrecordedWeek[];
    summary: BurnSummary;
    position: EngagementPositionSummary;
    can: { record: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

/** The Monday a week away from the given one, as the URL writes it. */
function shift(weekStart: string, weeks: number) {
    const date = new Date(`${weekStart}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + weeks * 7);

    return date.toISOString().slice(0, 10);
}

function WeekEntries({ week }: { week: BurnWeekView }) {
    return (
        <table className="w-full text-left text-[13px]">
            <tbody className="divide-y divide-ink/10 dark:divide-paper/10">
                {week.entries.map((entry) => (
                    <tr key={entry.id}>
                        <td className="px-4 py-1.5">{entry.attributedTo}</td>
                        <td className="px-4 py-1.5 text-stone dark:text-fog">
                            {entry.roleName}
                        </td>
                        <td className="px-4 py-1.5 font-plex-mono">
                            {entry.days} d
                        </td>
                        <td className="px-4 py-1.5 font-plex-mono text-stone dark:text-fog">
                            × {entry.costPerDay.formatted}
                        </td>
                        <td className="px-4 py-1.5 font-plex-mono font-semibold">
                            {entry.cost.formatted}
                        </td>
                        <td className="px-4 py-1.5">
                            <span className="border border-ink/30 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase dark:border-paper/30">
                                {entry.source}
                            </span>
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/**
 * Weekly burn entry (FA-16). The week arrives prefilled — logged time first,
 * a progress-derived suggestion for the profiles that logged none — and
 * recording freezes it. Corrections are new recordings: the week that was
 * replaced stays on the ledger underneath the one that replaced it.
 */
export default function EngagementBurn({
    engagement,
    week,
    weeks,
    unrecorded,
    summary,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Burn', href: burnIndex(engagement.id) },
        ],
        position,
    });

    const stats = [
        { label: 'Cost to date', value: summary.recordedBurn.formatted },
        { label: 'Days recorded', value: `${summary.recordedDays}` },
        {
            label: 'Budget used',
            value:
                summary.budgetPercent === null
                    ? '—'
                    : `${summary.budgetPercent}%`,
            warn: (summary.budgetPercent ?? 0) > 100,
        },
        {
            label: 'Unrecorded weeks',
            value: `${unrecorded.length}`,
            warn: unrecorded.length > 0,
        },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Burn`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Weekly burn
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Days per person or profile, priced from the pinned
                            rate card. Recording a week freezes it — cost to
                            date, the forecast at completion, margin and budget
                            % all move with it, and a correction is a new entry,
                            never an edit.
                        </p>
                    </div>
                    <div
                        className="flex flex-wrap gap-3"
                        data-test="burn-summary"
                    >
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={cn(
                                    'border-[1.5px] px-3 py-2',
                                    stat.warn
                                        ? 'border-rust text-rust'
                                        : 'border-ink dark:border-paper',
                                )}
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

                {!summary.hasBaseline && (
                    <div className="border-[1.5px] border-ochre px-4 py-3 text-[13px]">
                        <span className={cn(sectionLabel, 'text-ochre')}>
                            No approved baseline
                        </span>
                        <p className="mt-1 text-stone dark:text-fog">
                            Burn recorded now prices against the organization's
                            current rate card and counts as cost to date, but
                            there is no cost budget or margin to read it against
                            until a baseline is approved.
                        </p>
                    </div>
                )}

                {unrecorded.length > 0 && (
                    <div
                        className="border-[1.5px] border-rust px-4 py-3"
                        data-test="unrecorded-weeks"
                    >
                        <span className={cn(sectionLabel, 'text-rust')}>
                            {unrecorded.length} week
                            {unrecorded.length === 1 ? '' : 's'} unrecorded
                        </span>
                        <p className="mt-1 text-[13px] text-stone dark:text-fog">
                            A week nobody recorded is cost the forecast cannot
                            see.
                        </p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {unrecorded.map((entry) => (
                                <Link
                                    key={entry.weekStart}
                                    href={burnIndex(engagement.id, {
                                        query: { week: entry.weekStart },
                                    })}
                                    prefetch
                                    className={cn(
                                        'border-[1.5px] px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase transition-colors',
                                        entry.weekStart === week.weekStart
                                            ? 'border-rust bg-rust text-paper'
                                            : 'border-rust text-rust hover:bg-rust/10',
                                    )}
                                    data-test={`unrecorded-${entry.weekStart}`}
                                >
                                    {entry.weekLabel}
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <div>
                            <span className={sectionLabel}>
                                Week of {week.weekLabel}
                            </span>
                            <p className="mt-1 text-[12px] text-stone dark:text-fog">
                                {week.recorded
                                    ? `Recorded ${week.recordedAt}${week.recordedByName === null ? '' : ` by ${week.recordedByName}`} — recording again files a correction.`
                                    : `${week.loggedHours} h logged${
                                          week.weightedProgress === null
                                              ? ''
                                              : ` · deliverables ${Math.round(week.weightedProgress * 100)}% done`
                                      }`}
                            </p>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Link
                                href={burnIndex(engagement.id, {
                                    query: { week: shift(week.weekStart, -1) },
                                })}
                                prefetch
                                className="border-[1.5px] border-ink px-2 py-1 font-plex-mono text-[11px] font-semibold uppercase hover:bg-ink/5 dark:border-paper dark:hover:bg-paper/5"
                                data-test="burn-previous-week"
                            >
                                ‹ Earlier
                            </Link>
                            <Link
                                href={burnIndex(engagement.id, {
                                    query: { week: shift(week.weekStart, 1) },
                                })}
                                prefetch
                                className="border-[1.5px] border-ink px-2 py-1 font-plex-mono text-[11px] font-semibold uppercase hover:bg-ink/5 dark:border-paper dark:hover:bg-paper/5"
                                data-test="burn-next-week"
                            >
                                Later ›
                            </Link>
                        </div>
                    </div>

                    {can.record ? (
                        <BurnWeekForm
                            engagementId={engagement.id}
                            week={week}
                        />
                    ) : (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Recording burn is a delivery-management action.
                        </p>
                    )}
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>The ledger</span>
                        <Link
                            href={marginShow(engagement.id)}
                            prefetch
                            className="font-plex-mono text-[11px] font-semibold uppercase underline hover:text-rust"
                            data-test="open-margin"
                        >
                            Where this lands →
                        </Link>
                    </div>

                    {weeks.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Nothing recorded yet. Cost to date stays at zero
                            until a week is on the ledger — and so does every
                            figure derived from it.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Week</th>
                                        <th className={tableHeading}>Days</th>
                                        <th className={tableHeading}>Cost</th>
                                        <th className={tableHeading}>
                                            Recorded
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {weeks.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            data-test={`burn-week-${entry.weekStart}`}
                                        >
                                            <td className="px-4 py-2 align-top">
                                                <Link
                                                    href={burnIndex(
                                                        engagement.id,
                                                        {
                                                            query: {
                                                                week: entry.weekStart,
                                                            },
                                                        },
                                                    )}
                                                    prefetch
                                                    className="font-medium hover:text-rust"
                                                >
                                                    {entry.weekLabel}
                                                </Link>
                                                {entry.note !== null && (
                                                    <p className="mt-0.5 max-w-md text-[12px] text-stone dark:text-fog">
                                                        {entry.note}
                                                    </p>
                                                )}
                                                <div className="-mx-4 mt-1">
                                                    <WeekEntries week={entry} />
                                                </div>
                                                {entry.corrects.length > 0 && (
                                                    <div
                                                        className="mt-2 border-l-[1.5px] border-ochre pl-3"
                                                        data-test={`burn-corrections-${entry.weekStart}`}
                                                    >
                                                        <span className="font-plex-mono text-[10px] font-semibold text-ochre uppercase">
                                                            Superseded
                                                        </span>
                                                        {entry.corrects.map(
                                                            (previous) => (
                                                                <p
                                                                    key={
                                                                        previous.id
                                                                    }
                                                                    className="text-[12px] text-stone dark:text-fog"
                                                                >
                                                                    {
                                                                        previous
                                                                            .cost
                                                                            .formatted
                                                                    }{' '}
                                                                    over{' '}
                                                                    {
                                                                        previous.days
                                                                    }{' '}
                                                                    d, recorded{' '}
                                                                    {
                                                                        previous.recordedAt
                                                                    }
                                                                    {previous.recordedByName ===
                                                                    null
                                                                        ? ''
                                                                        : ` by ${previous.recordedByName}`}
                                                                </p>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 align-top font-plex-mono">
                                                {entry.days} d
                                            </td>
                                            <td className="px-4 py-2 align-top font-plex-mono font-semibold">
                                                {entry.cost.formatted}
                                            </td>
                                            <td className="px-4 py-2 align-top text-[12px] text-stone dark:text-fog">
                                                {entry.recordedAt}
                                                {entry.recordedByName !==
                                                    null && (
                                                    <>
                                                        <br />
                                                        {entry.recordedByName}
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
