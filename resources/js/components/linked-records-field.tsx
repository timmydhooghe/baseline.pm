import { useState } from 'react';
import { cn } from '@/lib/utils';
import type { RecordChip } from '@/types';

const chipKey = (record: { type: string; id: string }) =>
    `${record.type}::${record.id}`;

/**
 * The linked-record chips the governance ledgers are built on (FA-18, FA-19,
 * FA-20): records are picked, never typed, so a decision, a risk and a
 * dependency all point at the same rows the rest of the product does. The
 * selection posts as hidden inputs, which keeps the surrounding Inertia form
 * a plain HTML form.
 */
export default function LinkedRecordsField({
    records,
    defaultSelected = [],
    name = 'links',
    label = 'Linked records',
    hint,
    testId = 'linked-records',
}: {
    records: RecordChip[];
    defaultSelected?: { type: string; id: string }[];
    name?: string;
    label?: string;
    hint?: string;
    testId?: string;
}) {
    const [selected, setSelected] = useState<string[]>(
        defaultSelected.map(chipKey),
    );

    const toggle = (key: string) =>
        setSelected((current) =>
            current.includes(key)
                ? current.filter((entry) => entry !== key)
                : [...current, key],
        );

    return (
        <div className="grid gap-2" data-test={testId}>
            <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                {label}
            </span>
            {records.length === 0 ? (
                <p className="text-[12px] text-stone dark:text-fog">
                    This engagement has no records to link yet.
                </p>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {records.map((record) => {
                        const key = chipKey(record);
                        const isSelected = selected.includes(key);

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => toggle(key)}
                                aria-pressed={isSelected}
                                data-test={`${testId}-chip-${record.id}`}
                                className={cn(
                                    'border-[1.5px] px-2 py-1 text-left font-plex-mono text-[11px] font-semibold uppercase transition-colors',
                                    isSelected
                                        ? 'border-ink bg-ink text-paper dark:border-paper dark:bg-paper dark:text-ink'
                                        : 'border-ink/40 text-stone hover:border-ink dark:border-paper/40 dark:text-fog dark:hover:border-paper',
                                )}
                            >
                                <span className="opacity-70">
                                    {record.type_label}
                                </span>{' '}
                                {record.title}
                            </button>
                        );
                    })}
                </div>
            )}
            {hint !== undefined && (
                <p className="text-[12px] text-stone dark:text-fog">{hint}</p>
            )}
            {selected.map((key, index) => {
                const [type, id] = key.split('::');

                return (
                    <div key={key}>
                        <input
                            type="hidden"
                            name={`${name}[${index}][type]`}
                            value={type}
                        />
                        <input
                            type="hidden"
                            name={`${name}[${index}][id]`}
                            value={id}
                        />
                    </div>
                );
            })}
        </div>
    );
}
