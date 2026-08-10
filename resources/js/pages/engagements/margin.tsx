import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { formatCents } from '@/components/baseline-step-commercials';
import RecordChipList from '@/components/record-chip-list';
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as baselineShow } from '@/routes/engagements/baseline';
import { index as burnIndex } from '@/routes/engagements/burn';
import { index as dependenciesIndex } from '@/routes/engagements/dependencies';
import { show as marginShow } from '@/routes/engagements/margin';
import { index as risksIndex } from '@/routes/engagements/risks';
import { show as triageShow } from '@/routes/engagements/triage';
import type {
    EngagementPositionSummary,
    EngagementStatus,
    MarginAttribution,
    MarginForecastView,
    MarginRiskBand,
    MarginRoleRow,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    forecast: MarginForecastView;
    roles: MarginRoleRow[];
    attribution: MarginAttribution[];
    riskBand: MarginRiskBand;
    position: EngagementPositionSummary;
    can: { recordBurn: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const numeric = 'px-4 py-2 font-plex-mono';

/** Where a cause's records live, so the number can be walked back to them. */
const causeRoutes: Record<
    MarginAttribution['key'],
    (id: string) => { url: string }
> = {
    absorbed_scope_creep: triageShow,
    dependency_delay: dependenciesIndex,
    risk_materialised: risksIndex,
    staffing_premium: burnIndex,
    unattributed: burnIndex,
};

/**
 * How the margin forecast is derived (FA-15) and what moved it. Every figure
 * on this page is derived from records — the approved baseline's role mix,
 * the recorded burn weeks, the triage decisions, the dependency clock and the
 * risk register — and none of it is stored, so none of it can drift from
 * what it claims to be.
 */
export default function EngagementMargin({
    engagement,
    forecast,
    roles,
    attribution,
    riskBand,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Margin', href: marginShow(engagement.id) },
        ],
        position,
    });

    const overBudget = (forecast.forecastPercent ?? 0) > 100;

    const waterfall = [
        {
            label: 'Approved revenue',
            value: forecast.approvedRevenue?.formatted ?? '€ —',
            note:
                forecast.baselineVersion === null
                    ? 'No approved baseline'
                    : `baseline v${forecast.baselineVersion}`,
            href: baselineShow(engagement.id),
        },
        {
            label: 'Recorded burn',
            value: forecast.recordedBurn.formatted,
            note: `${forecast.recordedDays} d over ${forecast.weekCount} week${forecast.weekCount === 1 ? '' : 's'}`,
            href: burnIndex(engagement.id),
        },
        {
            label: 'Remaining effort',
            value: forecast.remainingCost?.formatted ?? '€ —',
            note:
                forecast.remainingDays === null
                    ? 'Needs an approved role mix'
                    : `${forecast.remainingDays} d at the pinned rate card${forecast.rateCardVersion === null ? '' : ` v${forecast.rateCardVersion}`}`,
            href: baselineShow(engagement.id),
        },
        {
            label: 'Forecast at completion',
            value: forecast.forecastCost?.formatted ?? '€ —',
            note:
                forecast.forecastPercent === null
                    ? 'Recorded burn plus remaining effort'
                    : `${forecast.forecastPercent}% of the ${forecast.costBudget?.formatted ?? '—'} cost budget`,
            href: burnIndex(engagement.id),
            warn: overBudget,
        },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Margin`} />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Margin derivation
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        {engagement.name}
                    </h1>
                    <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                        Margin is approved revenue less the forecast at
                        completion, and the forecast is recorded burn plus the
                        effort the plan has left, priced at the rate card the
                        baseline pinned. Nothing here is typed; everything
                        traces to a record.
                    </p>
                </div>

                {!forecast.hasBaseline ? (
                    <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                        <div className={sectionLabel}>
                            No approved baseline yet
                        </div>
                        <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                            There is no commitment to forecast against until a
                            baseline is approved.{' '}
                            {forecast.recordedBurn.formatted} of burn is already
                            on the ledger and will be read against the cost
                            budget the moment one exists.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="grid gap-4 md:grid-cols-4">
                            {waterfall.map((step) => (
                                <Link
                                    key={step.label}
                                    href={step.href}
                                    prefetch
                                    data-test={`margin-${step.label.toLowerCase().replaceAll(' ', '-')}`}
                                    className={cn(
                                        'border-[1.5px] p-4 transition-colors',
                                        step.warn
                                            ? 'border-rust text-rust hover:bg-rust/5'
                                            : 'border-ink hover:bg-ink/5 dark:border-paper dark:hover:bg-paper/5',
                                    )}
                                >
                                    <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                        {step.label}
                                    </div>
                                    <div className="mt-1 font-plex-mono text-[22px] font-semibold">
                                        {step.value}
                                    </div>
                                    <div className="mt-1 text-[12px] text-stone dark:text-fog">
                                        {step.note}
                                    </div>
                                </Link>
                            ))}
                        </div>

                        <div
                            className="border-[1.5px] border-ink dark:border-paper"
                            data-test="margin-band"
                        >
                            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                <span className={sectionLabel}>
                                    Margin forecast &amp; risk band
                                </span>
                            </div>
                            <div className="grid divide-ink/15 sm:grid-cols-3 sm:divide-x dark:divide-paper/15">
                                <div className="px-4 py-3">
                                    <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                        Planned at baseline
                                    </div>
                                    <div className="mt-1 font-plex-mono text-[22px] font-semibold">
                                        {forecast.plannedMargin?.formatted ??
                                            '€ —'}
                                    </div>
                                    <div className="mt-1 text-[12px] text-stone dark:text-fog">
                                        {forecast.plannedMarginPercent ?? '—'}%
                                        of contract value
                                    </div>
                                </div>
                                <div className="px-4 py-3">
                                    <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                        Forecast now
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-1 font-plex-mono text-[22px] font-semibold',
                                            (forecast.margin?.amount ?? 0) <
                                                0 && 'text-rust',
                                        )}
                                    >
                                        {forecast.margin?.formatted ?? '€ —'}
                                    </div>
                                    <div className="mt-1 text-[12px] text-stone dark:text-fog">
                                        {forecast.marginPercent ?? '—'}% ·
                                        variance{' '}
                                        <span
                                            className={cn(
                                                'font-semibold',
                                                (forecast.variance?.amount ??
                                                    0) > 0 && 'text-rust',
                                            )}
                                        >
                                            {forecast.variance?.formatted ??
                                                '€ —'}
                                        </span>{' '}
                                        against plan
                                    </div>
                                </div>
                                <Link
                                    href={risksIndex(engagement.id)}
                                    prefetch
                                    className="px-4 py-3 transition-colors hover:bg-ink/5 dark:hover:bg-paper/5"
                                    data-test="margin-risk-band"
                                >
                                    <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                        At weighted risk
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-1 font-plex-mono text-[22px] font-semibold',
                                            (riskBand.low?.amount ?? 0) < 0 &&
                                                'text-rust',
                                        )}
                                    >
                                        {riskBand.low?.formatted ?? '€ —'}
                                    </div>
                                    <div className="mt-1 text-[12px] text-stone dark:text-fog">
                                        {riskBand.liveRisks} live risk
                                        {riskBand.liveRisks === 1
                                            ? ''
                                            : 's'} ·{' '}
                                        {riskBand.weightedExposure.formatted}{' '}
                                        probability-weighted of{' '}
                                        {riskBand.exposure.formatted}
                                    </div>
                                </Link>
                            </div>
                        </div>

                        <div className="border-[1.5px] border-ink dark:border-paper">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                <span className={sectionLabel}>
                                    Why it moved
                                </span>
                                <span className="font-plex-mono text-[11px] font-semibold uppercase">
                                    {forecast.variance?.formatted ?? '€ —'} vs
                                    plan
                                </span>
                            </div>

                            {attribution.length === 0 ? (
                                <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                                    The forecast still matches the cost budget
                                    exactly. Nothing has moved it.
                                </p>
                            ) : (
                                <div className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {attribution.map((cause) => {
                                        const route = causeRoutes[cause.key];

                                        return (
                                            <div
                                                key={cause.key}
                                                className="flex flex-wrap items-start justify-between gap-4 px-4 py-3"
                                                data-test={`attribution-${cause.key}`}
                                            >
                                                <div className="max-w-2xl">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-plex-mono text-[12px] font-semibold uppercase">
                                                            {cause.label}
                                                        </span>
                                                        {route !==
                                                            undefined && (
                                                            <Link
                                                                href={
                                                                    route(
                                                                        engagement.id,
                                                                    ).url
                                                                }
                                                                prefetch
                                                                className="font-plex-mono text-[11px] font-semibold uppercase underline hover:text-rust"
                                                            >
                                                                source →
                                                            </Link>
                                                        )}
                                                    </div>
                                                    <p className="mt-1 text-[13px] text-stone dark:text-fog">
                                                        {cause.detail}
                                                    </p>
                                                    {cause.records.length >
                                                        0 && (
                                                        <RecordChipList
                                                            records={
                                                                cause.records
                                                            }
                                                            className="mt-2"
                                                        />
                                                    )}
                                                    {cause.moreCount > 0 && (
                                                        <p className="mt-1 text-[12px] text-stone dark:text-fog">
                                                            and{' '}
                                                            {cause.moreCount}{' '}
                                                            more
                                                        </p>
                                                    )}
                                                </div>
                                                <div
                                                    className={cn(
                                                        'font-plex-mono text-[20px] font-semibold',
                                                        cause.amount.amount >
                                                            0 && 'text-rust',
                                                        cause.amount.amount <
                                                            0 && 'text-moss',
                                                    )}
                                                >
                                                    {cause.amount.formatted}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        <div className="border-[1.5px] border-ink dark:border-paper">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                <span className={sectionLabel}>
                                    Plan against recorded, per profile
                                </span>
                                {can.recordBurn && (
                                    <Link
                                        href={burnIndex(engagement.id)}
                                        prefetch
                                        className="font-plex-mono text-[11px] font-semibold uppercase underline hover:text-rust"
                                        data-test="open-burn"
                                    >
                                        Record a week →
                                    </Link>
                                )}
                            </div>

                            {roles.length === 0 ? (
                                <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                                    The baseline carries no role mix, so there
                                    is no remaining effort to forecast.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-[13px]">
                                        <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                            <tr>
                                                <th className={tableHeading}>
                                                    Profile
                                                </th>
                                                <th className={tableHeading}>
                                                    Planned
                                                </th>
                                                <th className={tableHeading}>
                                                    Recorded
                                                </th>
                                                <th className={tableHeading}>
                                                    Remaining
                                                </th>
                                                <th className={tableHeading}>
                                                    Forecast cost
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                            {roles.map((role) => (
                                                <tr
                                                    key={role.name}
                                                    data-test={`margin-role-${role.name}`}
                                                >
                                                    <td className="px-4 py-2">
                                                        <div className="font-medium">
                                                            {role.name}
                                                        </div>
                                                        <div className="text-[12px] text-stone dark:text-fog">
                                                            {
                                                                role.costPerDay
                                                                    .formatted
                                                            }{' '}
                                                            a day
                                                        </div>
                                                        {role.unplanned && (
                                                            <span className="mt-1 inline-block border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold text-rust uppercase">
                                                                Unplanned
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className={numeric}>
                                                        {role.plannedDays} d
                                                        <div className="text-[12px] text-stone dark:text-fog">
                                                            {
                                                                role.plannedCost
                                                                    .formatted
                                                            }
                                                        </div>
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            numeric,
                                                            role.overrunDays >
                                                                0 &&
                                                                'font-semibold text-rust',
                                                        )}
                                                    >
                                                        {role.recordedDays} d
                                                        <div className="text-[12px] text-stone dark:text-fog">
                                                            {
                                                                role
                                                                    .recordedCost
                                                                    .formatted
                                                            }
                                                            {role.overrunDays >
                                                                0 &&
                                                                ` · ${role.overrunDays} d over`}
                                                        </div>
                                                    </td>
                                                    <td className={numeric}>
                                                        {role.remainingDays} d
                                                        <div className="text-[12px] text-stone dark:text-fog">
                                                            {
                                                                role
                                                                    .remainingCost
                                                                    .formatted
                                                            }
                                                        </div>
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            numeric,
                                                            'font-semibold',
                                                        )}
                                                    >
                                                        {formatCents(
                                                            role.recordedCost
                                                                .amount +
                                                                role
                                                                    .remainingCost
                                                                    .amount,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
