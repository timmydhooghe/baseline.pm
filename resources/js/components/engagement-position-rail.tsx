import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { show as triageShow } from '@/routes/engagements/triage';
import type { EngagementPositionSummary } from '@/types';

const blockLabel =
    'font-plex-mono text-[11px] font-semibold text-stone dark:text-fog';

/**
 * The engagement's commercial position rail (FA-10, FA-14). Contracted value
 * and unbilled risk are live; the waterfall's remaining lines (burned,
 * pending CRs, accepted) arrive with their own features and stay dashed
 * until then. Unbilled risk clicks through to the triage inbox it derives
 * from.
 */
export default function EngagementPositionRail({
    position,
}: {
    position: EngagementPositionSummary;
}) {
    const risk = position.unbilledRisk;
    const hasRisk = risk.count > 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                Commercial position
            </div>

            <div className="border-[1.5px] border-ink bg-paper p-3 dark:border-paper dark:bg-ink">
                <div className={blockLabel}>CONTRACTED</div>
                <div className="mt-1 font-plex-mono text-[20px] font-semibold">
                    {position.contracted?.formatted ?? '€ —'}
                </div>
                {position.baselineVersion !== null && (
                    <div className="mt-1 text-[11px] text-stone dark:text-fog">
                        baseline v{position.baselineVersion}
                    </div>
                )}
            </div>

            <Link
                href={triageShow(position.engagementId)}
                prefetch
                data-test="rail-unbilled-risk"
                className={cn(
                    'block border-[1.5px] bg-paper p-3 transition-colors dark:bg-ink',
                    hasRisk
                        ? 'border-rust hover:bg-rust/5'
                        : 'border-ink hover:bg-ink/5 dark:border-paper dark:hover:bg-paper/5',
                )}
            >
                <div
                    className={cn(
                        'font-plex-mono text-[11px] font-semibold',
                        hasRisk ? 'text-rust' : 'text-stone dark:text-fog',
                    )}
                >
                    UNBILLED RISK
                </div>
                <div
                    className={cn(
                        'mt-1 font-plex-mono text-[20px] font-semibold',
                        hasRisk && 'text-rust',
                    )}
                >
                    {risk.price === null
                        ? '€ —'
                        : `${risk.price.formatted}${risk.unpriced > 0 ? '+' : ''}`}
                </div>
                <div className="mt-1 text-[11px] text-stone dark:text-fog">
                    {risk.count === 0
                        ? 'No unresolved drift'
                        : `${risk.count} unresolved ${risk.count === 1 ? 'item' : 'items'}${
                              risk.price !== null && risk.unpriced > 0
                                  ? ` · ${risk.unpriced} unpriced`
                                  : ''
                          }`}
                </div>
            </Link>

            {['Burned', 'Position'].map((label) => (
                <div
                    key={label}
                    className="border-[1.5px] border-ink bg-paper p-3 dark:border-paper dark:bg-ink"
                >
                    <div className={blockLabel}>{label.toUpperCase()}</div>
                    <div className="mt-1 font-plex-mono text-[20px] font-semibold">
                        € —
                    </div>
                </div>
            ))}
        </div>
    );
}
