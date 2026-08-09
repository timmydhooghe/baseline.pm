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
    defaultSelected?: RecordChip[];
    name?: string;
    label?: string;
    hint?: string;
    testId?: string;
}) {
    const [selected, setSelected] = useState<string[]>(
        defaultSelected.map(chipKey),
    );

    /*
     * Everything already linked stays togglable even when the picker no
     * longer offers it — a record from an earlier baseline version, or one
     * since deleted. Rendering only the offered list would leave those
     * links posted as hidden inputs with no way to remove them, and a
     * deleted target would then fail validation on every save.
     */
    const offered = new Set(records.map(chipKey));
    const options: RecordChip[] = [
        ...records,
        ...defaultSelected.filter((record) => !offered.has(chipKey(record))),
    ];

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
            {options.length === 0 ? (
                <p className="text-[12px] text-stone dark:text-fog">
                    This engagement has no records to link yet.
                </p>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {options.map((record) => {
                        const key = chipKey(record);
                        const isSelected = selected.includes(key);
                        const isStale = !offered.has(key);

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
                                {isStale && (
                                    <span className="opacity-70">
                                        {' '}
                                        · no longer offered
                                    </span>
                                )}
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
