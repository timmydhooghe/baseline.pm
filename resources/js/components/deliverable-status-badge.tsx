import { cn } from '@/lib/utils';
import type { DeliverableStatus } from '@/types';

const statusClasses: Record<DeliverableStatus, string> = {
    in_progress: 'border-ink text-stone dark:border-paper dark:text-fog',
    awaiting_acceptance: 'border-ink bg-sun text-ink dark:border-paper',
    accepted: 'border-moss bg-moss text-paper',
    rejected: 'border-rust text-rust',
};

export default function DeliverableStatusBadge({
    status,
    label,
    className,
}: {
    status: DeliverableStatus;
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
