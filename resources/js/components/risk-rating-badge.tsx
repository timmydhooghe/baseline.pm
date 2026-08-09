import { cn } from '@/lib/utils';
import type { RiskRating } from '@/types';

/**
 * A risk's probability × impact rating (FA-19). The score, not the wording,
 * carries the weight: 9 is the H×H entry that surfaces on Today.
 */
export default function RiskRatingBadge({
    probability,
    impact,
    score,
    className,
}: {
    probability: RiskRating;
    impact: RiskRating;
    score: number;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-block border-[1.5px] px-2 py-0.5 font-plex-mono text-[11px] font-semibold whitespace-nowrap uppercase',
                score >= 9
                    ? 'border-rust bg-rust text-paper'
                    : score >= 6
                      ? 'border-ochre text-ochre'
                      : 'border-ink text-stone dark:border-paper dark:text-fog',
                className,
            )}
            title={`Probability ${probability} × impact ${impact}`}
        >
            {probability.charAt(0)}×{impact.charAt(0)} · {score}
        </span>
    );
}
