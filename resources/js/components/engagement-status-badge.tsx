import { cn } from '@/lib/utils';
import type { EngagementStatus } from '@/types';

const statusClasses: Record<EngagementStatus, string> = {
    draft: 'border-ink text-stone dark:border-paper dark:text-fog',
    preparing_baseline: 'border-ochre text-ochre',
    awaiting_baseline_approval: 'border-ink bg-sun text-ink dark:border-paper',
    active: 'border-moss bg-moss text-paper',
    awaiting_final_acceptance: 'border-ink bg-sun text-ink dark:border-paper',
    completed: 'border-moss text-moss',
    archived:
        'border-ink/40 text-stone opacity-70 dark:border-paper/40 dark:text-fog',
};

export default function EngagementStatusBadge({
    status,
    label,
    className,
}: {
    status: EngagementStatus;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-block border-[1.5px] px-2 py-0.5 font-plex-mono text-[11px] font-semibold whitespace-nowrap uppercase',
                statusClasses[status],
                className,
            )}
        >
            {label}
        </span>
    );
}
