import { cn } from '@/lib/utils';
import type { ChangeRequestStatus } from '@/types';

const statusClasses: Record<ChangeRequestStatus, string> = {
    draft: 'border-ink text-stone dark:border-paper dark:text-fog',
    under_assessment: 'border-ochre text-ochre',
    customer_proposal: 'border-ink text-ink dark:border-paper dark:text-paper',
    awaiting_approval: 'border-ink bg-sun text-ink dark:border-paper',
    approved: 'border-moss bg-moss text-paper',
    rejected: 'border-rust text-rust',
};

export default function ChangeRequestStatusBadge({
    status,
    label,
    className,
}: {
    status: ChangeRequestStatus;
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
