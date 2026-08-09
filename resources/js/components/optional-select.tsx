import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';

/**
 * The sentinel standing in for "nothing chosen". Radix refuses an empty
 * string as an item value, so the cleared state needs a value of its own and
 * is translated back to an empty field on the way out.
 */
const NONE = '__none__';

export const selectTriggerClass =
    'w-full rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

/**
 * A branded dropdown for a field that may legitimately hold nothing — an
 * unassigned risk owner, a decision that supersedes none. The choice posts
 * through a hidden input so clearing sends an empty field, which the
 * framework turns back into null.
 */
export default function OptionalSelect({
    name,
    id,
    options,
    defaultValue,
    placeholder,
    emptyLabel,
    testId,
    className,
}: {
    name: string;
    id?: string;
    options: SelectOption[];
    defaultValue?: string | null;
    placeholder: string;
    emptyLabel: string;
    testId?: string;
    className?: string;
}) {
    const [value, setValue] = useState(
        defaultValue === null || defaultValue === undefined
            ? NONE
            : defaultValue,
    );

    return (
        <>
            <Select value={value} onValueChange={setValue}>
                <SelectTrigger
                    id={id}
                    data-test={testId}
                    className={cn(selectTriggerClass, className)}
                >
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{emptyLabel}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <input
                type="hidden"
                name={name}
                value={value === NONE ? '' : value}
            />
        </>
    );
}
