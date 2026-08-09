import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Column = {
    key: string;
    label: string;
    placeholder?: string;
    required?: boolean;
};

type Row = Record<string, string>;

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

/**
 * A repeatable set of typed rows — the shape FA-18 asks the decision ledger
 * for: alternatives with the reason they lost, participants, evidence links.
 *
 * Removing the last row posts a `<name>_cleared` flag. Without it an empty
 * set is indistinguishable from a form that never carried the field, and the
 * server has to treat that as "unchanged" so editing a transcript-proposed
 * draft cannot silently erase the participants it extracted.
 */
export default function StructuredRowsField({
    name,
    label,
    columns,
    defaultRows = [],
    addLabel = 'Add a row',
    hint,
    testId,
}: {
    name: string;
    label: string;
    columns: Column[];
    defaultRows?: Record<string, string | null>[];
    addLabel?: string;
    hint?: string;
    testId?: string;
}) {
    const [rows, setRows] = useState<Row[]>(
        defaultRows.map((row) =>
            Object.fromEntries(
                columns.map((column) => [column.key, row[column.key] ?? '']),
            ),
        ),
    );

    const blankRow = (): Row =>
        Object.fromEntries(columns.map((column) => [column.key, '']));

    return (
        <div className="grid gap-2" data-test={testId ?? `${name}-rows`}>
            <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                {label}
            </span>

            {rows.length === 0 ? (
                <p className="text-[12px] text-stone dark:text-fog">
                    None recorded.
                </p>
            ) : (
                rows.map((row, index) => (
                    <div
                        key={index}
                        className="flex flex-wrap items-end gap-2"
                        data-test={`${name}-row-${index}`}
                    >
                        {columns.map((column) => (
                            <div
                                key={column.key}
                                className="grid min-w-40 flex-1 gap-1"
                            >
                                <label
                                    htmlFor={`${name}-${index}-${column.key}`}
                                    className="font-plex-mono text-[10px] font-semibold text-stone uppercase dark:text-fog"
                                >
                                    {column.label}
                                </label>
                                <Input
                                    id={`${name}-${index}-${column.key}`}
                                    name={`${name}[${index}][${column.key}]`}
                                    required={column.required}
                                    placeholder={column.placeholder}
                                    value={row[column.key]}
                                    onChange={(event) =>
                                        setRows((current) =>
                                            current.map((entry, position) =>
                                                position === index
                                                    ? {
                                                          ...entry,
                                                          [column.key]:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : entry,
                                            ),
                                        )
                                    }
                                    className={cn(fieldClass, 'w-full')}
                                />
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                setRows((current) =>
                                    current.filter(
                                        (_, position) => position !== index,
                                    ),
                                )
                            }
                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                            data-test={`${name}-remove-${index}`}
                        >
                            Remove
                        </Button>
                    </div>
                ))
            )}

            {rows.length === 0 && (
                <input type="hidden" name={`${name}_cleared`} value="1" />
            )}

            <Button
                type="button"
                variant="outline"
                onClick={() => setRows((current) => [...current, blankRow()])}
                className="w-fit rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                data-test={`${name}-add`}
            >
                {addLabel}
            </Button>

            {hint !== undefined && (
                <p className="text-[12px] text-stone dark:text-fog">{hint}</p>
            )}
        </div>
    );
}
