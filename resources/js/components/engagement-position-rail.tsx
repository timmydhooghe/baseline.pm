import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { show as baselineShow } from '@/routes/engagements/baseline';
import { index as burnIndex } from '@/routes/engagements/burn';
import { index as changeRequestsIndex } from '@/routes/engagements/change-requests';
import { index as deliverablesIndex } from '@/routes/engagements/deliverables';
import { show as marginShow } from '@/routes/engagements/margin';
import { show as triageShow } from '@/routes/engagements/triage';
import type { EngagementPositionSummary } from '@/types';

const blockLabel = 'font-plex-mono text-[11px] font-semibold';

const accents = {
    ink: {
        border: 'border-ink hover:bg-ink/5 dark:border-paper dark:hover:bg-paper/5',
        text: 'text-stone dark:text-fog',
        bar: 'bg-ink dark:bg-paper',
    },
    moss: {
        border: 'border-moss hover:bg-moss/5',
        text: 'text-moss',
        bar: 'bg-moss',
    },
    ochre: {
        border: 'border-ochre hover:bg-ochre/5',
        text: 'text-ochre',
        bar: 'bg-ochre',
    },
    rust: {
        border: 'border-rust hover:bg-rust/5',
        text: 'text-rust',
        bar: 'bg-rust',
    },
} as const;

type Accent = keyof typeof accents;

/**
 * One block of the waterfall. The bar under the figure carries its share of
 * the largest block on the rail, so the proportions are read before the
 * numbers are — and a block worth nothing shows nothing rather than a
 * misleading sliver.
 */
function Block({
    label,
    value,
    note,
    href,
    accent,
    share,
    testId,
}: {
    label: string;
    value: string;
    note?: ReactNode;
    href: string;
    accent: Accent;
    share: number;
    testId: string;
}) {
    const tone = accents[accent];

    return (
        <Link
            href={href}
            prefetch
            data-test={testId}
            className={cn(
                'block border-[1.5px] bg-paper p-3 transition-colors dark:bg-ink',
                tone.border,
            )}
        >
            <div className={cn(blockLabel, tone.text)}>{label}</div>
            <div
                className={cn(
                    'mt-1 font-plex-mono text-[20px] font-semibold',
                    accent !== 'ink' && tone.text,
                )}
            >
                {value}
            </div>
            {share > 0 && (
                <div
                    aria-hidden
                    className="mt-2 h-[3px] bg-ink/10 dark:bg-paper/10"
                >
                    <div
                        className={cn('h-full', tone.bar)}
                        style={{ width: `${Math.min(100, share * 100)}%` }}
                    />
                </div>
            )}
            {note !== undefined && (
                <div className="mt-1 text-[11px] text-stone dark:text-fog">
                    {note}
                </div>
            )}
        </Link>
    );
}

/**
 * The engagement's commercial position rail (FA-14). APPROVED, ACCEPTED,
 * PENDING CR and UNBILLED RISK render as live proportional blocks, followed
 * by the burn recorded against them and the margin forecast and budget % they
 * derive (FA-15, FA-17). Every figure clicks through to the record it comes
 * from — the rail asserts nothing it cannot show.
 *
 * Cost, burn and margin are internal (FA-27): for viewers without rate card
 * access those blocks are simply not in the payload, and the rail stops at
 * what they may read.
 */
export default function EngagementPositionRail({
    position,
}: {
    position: EngagementPositionSummary;
}) {
    const { accepted, pendingChange, unbilledRisk, burn, margin } = position;
    const engagementId = position.engagementId;

    const scale = Math.max(
        position.contracted?.amount ?? 0,
        accepted.value.amount,
        pendingChange.price.amount,
        unbilledRisk.price?.amount ?? 0,
        1,
    );
    const share = (amount: number) => amount / scale;

    return (
        <div className="flex flex-col gap-3">
            <div className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                Commercial position
            </div>

            <Block
                label="APPROVED"
                value={position.contracted?.formatted ?? '€ —'}
                note={
                    position.baselineVersion === null
                        ? 'No approved baseline yet'
                        : `baseline v${position.baselineVersion}`
                }
                href={baselineShow(engagementId).url}
                accent="ink"
                share={share(position.contracted?.amount ?? 0)}
                testId="rail-approved"
            />

            <Block
                label="ACCEPTED"
                value={accepted.value.formatted}
                note={
                    accepted.total === 0
                        ? 'No deliverables yet'
                        : `${accepted.count}/${accepted.total} signed off`
                }
                href={deliverablesIndex(engagementId).url}
                accent={accepted.count > 0 ? 'moss' : 'ink'}
                share={share(accepted.value.amount)}
                testId="rail-accepted"
            />

            <Block
                label="PENDING CR"
                value={`${pendingChange.price.formatted}${pendingChange.unpriced > 0 ? '+' : ''}`}
                note={
                    pendingChange.count === 0
                        ? 'Nothing in flight'
                        : `${pendingChange.count} in flight${
                              pendingChange.unpriced > 0
                                  ? ` · ${pendingChange.unpriced} unpriced`
                                  : ''
                          }`
                }
                href={changeRequestsIndex(engagementId).url}
                accent={pendingChange.count > 0 ? 'ochre' : 'ink'}
                share={share(pendingChange.price.amount)}
                testId="rail-pending-change"
            />

            <Block
                label="UNBILLED RISK"
                value={
                    unbilledRisk.price === null
                        ? '€ —'
                        : `${unbilledRisk.price.formatted}${unbilledRisk.unpriced > 0 ? '+' : ''}`
                }
                note={
                    unbilledRisk.count === 0
                        ? 'No unresolved scope creep'
                        : `${unbilledRisk.count} unresolved ${unbilledRisk.count === 1 ? 'item' : 'items'}${
                              unbilledRisk.price !== null &&
                              unbilledRisk.unpriced > 0
                                  ? ` · ${unbilledRisk.unpriced} unpriced`
                                  : ''
                          }`
                }
                href={triageShow(engagementId).url}
                accent={unbilledRisk.count > 0 ? 'rust' : 'ink'}
                share={share(unbilledRisk.price?.amount ?? 0)}
                testId="rail-unbilled-risk"
            />

            {burn !== null && (
                <Block
                    label="BURNED"
                    value={burn.recorded.formatted}
                    note={
                        burn.unrecordedWeeks > 0 ? (
                            <span className="font-semibold text-rust">
                                {burn.unrecordedWeeks} week
                                {burn.unrecordedWeeks === 1 ? '' : 's'}{' '}
                                unrecorded
                            </span>
                        ) : burn.budgetPercent === null ? (
                            `${burn.weeks} week${burn.weeks === 1 ? '' : 's'} recorded`
                        ) : (
                            `${burn.budgetPercent}% of budget · ${burn.weeks} week${burn.weeks === 1 ? '' : 's'}`
                        )
                    }
                    href={burnIndex(engagementId).url}
                    accent={burn.unrecordedWeeks > 0 ? 'rust' : 'ink'}
                    share={
                        burn.budgetPercent === null
                            ? 0
                            : burn.budgetPercent / 100
                    }
                    testId="rail-burned"
                />
            )}

            {margin !== null && (
                <Block
                    label="MARGIN FORECAST"
                    value={margin.forecast.formatted}
                    note={
                        <>
                            {margin.percent === null
                                ? 'Derived from the forecast at completion'
                                : `${margin.percent}% · planned ${margin.plannedPercent ?? '—'}%`}
                            {!margin.weightedExposure.amount ? null : (
                                <>
                                    <br />
                                    down to {margin.low.formatted} at
                                    probability-weighted risk
                                </>
                            )}
                        </>
                    }
                    href={marginShow(engagementId).url}
                    accent={
                        margin.forecast.amount < 0
                            ? 'rust'
                            : margin.variance.amount > 0
                              ? 'ochre'
                              : 'moss'
                    }
                    share={0}
                    testId="rail-margin"
                />
            )}
        </div>
    );
}
