import { cn } from '@/lib/utils';
import type { RecordChip } from '@/types';

/**
 * Reads back the records a governance entry names. Kept deliberately plain:
 * the chip is a fact about the ledger, not a control.
 */
export default function RecordChipList({
    records,
    empty = 'No linked records.',
    className,
    testId,
}: {
    records: RecordChip[];
    empty?: string;
    className?: string;
    testId?: string;
}) {
    if (records.length === 0) {
        return (
            <p
                className={cn(
                    'text-[12px] text-stone dark:text-fog',
                    className,
                )}
                data-test={testId}
            >
                {empty}
            </p>
        );
    }

    return (
        <div
            className={cn('flex flex-wrap gap-1.5', className)}
            data-test={testId}
        >
            {records.map((record) => (
                <span
                    key={`${record.type}-${record.id}`}
                    className="border-[1.5px] border-ink/40 px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase dark:border-paper/40"
                >
                    <span className="text-stone dark:text-fog">
                        {record.type_label}
                    </span>{' '}
                    {record.title}
                </span>
            ))}
        </div>
    );
}
