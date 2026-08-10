import { Form } from '@inertiajs/react';
import { useState } from 'react';
import BurnController from '@/actions/App/Http/Controllers/BurnController';
import { formatCents } from '@/components/baseline-step-commercials';
import InputError from '@/components/input-error';
import { selectTriggerClass } from '@/components/optional-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { BurnSource, BurnWeekForm } from '@/types';

type Row = {
    key: number;
    roleId: string;
    personName: string;
    userId: string | null;
    days: string;
    /** What the prefill proposed, so an edited figure records as manual. */
    suggestedDays: string;
    suggestedSource: BurnSource;
    basis: string;
};

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const heading =
    'px-3 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const days = (value: number) => String(Math.round(value * 100) / 100);

/**
 * The weekly burn entry form (FA-16). Days are the only typed field: the cost
 * beside each line derives from the profile's rate as you type, and the
 * server prices the week again from the same rate card when it records it.
 *
 * A prefilled figure that gets edited records as manual — the ledger says who
 * decided the number, not who first proposed it.
 */
export default function BurnWeekForm({
    engagementId,
    week,
}: {
    engagementId: string;
    week: BurnWeekForm;
}) {
    const [rows, setRows] = useState<Row[]>(() =>
        week.lines.map((line, index) => ({
            key: index,
            roleId: line.roleId ?? '',
            personName: line.personName ?? '',
            userId: line.userId,
            days: days(line.days),
            suggestedDays: days(line.days),
            suggestedSource: line.source,
            basis: line.basis,
        })),
    );
    const [nextKey, setNextKey] = useState(week.lines.length);

    const rateOf = (roleId: string) =>
        week.roles.find((role) => role.value === roleId)?.costPerDay.amount ??
        0;

    const costOf = (row: Row) =>
        Math.round((Number(row.days) || 0) * rateOf(row.roleId));

    const sourceOf = (row: Row): BurnSource =>
        row.days === row.suggestedDays ? row.suggestedSource : 'manual';

    const total = rows.reduce((sum, row) => sum + costOf(row), 0);
    const update = (key: number, patch: Partial<Row>) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...patch } : row,
            ),
        );

    return (
        <Form
            {...BurnController.store.form(engagementId)}
            className="flex flex-col"
            data-test="burn-week-form"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="week_start"
                        value={week.weekStart}
                    />

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-[13px]">
                            <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                <tr>
                                    <th className={heading}>Who</th>
                                    <th className={heading}>Profile</th>
                                    <th className={heading}>Days</th>
                                    <th className={heading}>Cost</th>
                                    <th className={heading}>Source</th>
                                    <th className={heading} />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                {rows.map((row, index) => (
                                    <tr
                                        key={row.key}
                                        data-test={`burn-row-${index}`}
                                    >
                                        <td className="px-3 py-2 align-top">
                                            <Input
                                                name={`lines[${index}][person_name]`}
                                                value={row.personName}
                                                onChange={(event) =>
                                                    update(row.key, {
                                                        personName:
                                                            event.target.value,
                                                    })
                                                }
                                                placeholder="Whole profile"
                                                className={cn(
                                                    fieldClass,
                                                    'min-w-40',
                                                )}
                                                data-test={`burn-person-${index}`}
                                            />
                                            {row.userId !== null && (
                                                <input
                                                    type="hidden"
                                                    name={`lines[${index}][user_id]`}
                                                    value={row.userId}
                                                />
                                            )}
                                            <p className="mt-1 max-w-56 text-[11px] text-stone dark:text-fog">
                                                {row.basis}
                                            </p>
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${index}.person_name`
                                                    ]
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Select
                                                name={`lines[${index}][rate_card_role_id]`}
                                                value={row.roleId}
                                                onValueChange={(value) =>
                                                    update(row.key, {
                                                        roleId: value,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    className={cn(
                                                        selectTriggerClass,
                                                        'min-w-44',
                                                    )}
                                                    data-test={`burn-role-${index}`}
                                                >
                                                    <SelectValue placeholder="Pick a profile" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {week.roles.map((role) => (
                                                        <SelectItem
                                                            key={role.value}
                                                            value={role.value}
                                                        >
                                                            {role.label} ·{' '}
                                                            {
                                                                role.costPerDay
                                                                    .formatted
                                                            }
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${index}.rate_card_role_id`
                                                    ]
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Input
                                                name={`lines[${index}][days]`}
                                                type="number"
                                                step="0.25"
                                                min="0"
                                                required
                                                value={row.days}
                                                onChange={(event) =>
                                                    update(row.key, {
                                                        days: event.target
                                                            .value,
                                                    })
                                                }
                                                className={cn(
                                                    fieldClass,
                                                    'w-24 font-plex-mono',
                                                )}
                                                data-test={`burn-days-${index}`}
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${index}.days`
                                                    ]
                                                }
                                            />
                                        </td>
                                        <td
                                            className="px-3 py-2 align-top font-plex-mono font-semibold"
                                            data-test={`burn-cost-${index}`}
                                        >
                                            {row.roleId === ''
                                                ? '€ —'
                                                : formatCents(costOf(row))}
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase dark:border-paper/40">
                                                {sourceOf(row)}
                                            </span>
                                            <input
                                                type="hidden"
                                                name={`lines[${index}][source]`}
                                                value={sourceOf(row)}
                                            />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setRows((current) =>
                                                        current.filter(
                                                            (entry) =>
                                                                entry.key !==
                                                                row.key,
                                                        ),
                                                    )
                                                }
                                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                                data-test={`burn-remove-${index}`}
                                            >
                                                Remove
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <p className="px-3 py-4 text-[13px] text-stone dark:text-fog">
                            No lines yet. Add the profiles that worked this week
                            — a week with nothing in it is not the same as a
                            week nobody worked.
                        </p>
                    )}

                    <InputError message={errors.lines} className="px-3 pt-2" />

                    <div className="flex flex-wrap items-end justify-between gap-4 border-t-[1.5px] border-ink px-3 py-3 dark:border-paper">
                        <div className="flex flex-wrap items-end gap-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setRows((current) => [
                                        ...current,
                                        {
                                            key: nextKey,
                                            roleId: '',
                                            personName: '',
                                            userId: null,
                                            days: '',
                                            suggestedDays: '',
                                            suggestedSource: 'manual',
                                            basis: 'Entered by hand.',
                                        },
                                    ]);
                                    setNextKey((key) => key + 1);
                                }}
                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                data-test="burn-add-line"
                            >
                                Add a line
                            </Button>

                            <div className="grid gap-1">
                                <Label
                                    htmlFor="burn-note"
                                    className="font-plex-mono text-[10px] font-semibold text-stone uppercase dark:text-fog"
                                >
                                    {week.recorded
                                        ? 'Why this correction'
                                        : 'Note (optional)'}
                                </Label>
                                <Input
                                    id="burn-note"
                                    name="note"
                                    className={cn(fieldClass, 'w-72')}
                                    placeholder={
                                        week.recorded
                                            ? 'e.g. Sara logged Friday late'
                                            : ''
                                    }
                                    data-test="burn-note"
                                />
                                <InputError message={errors.note} />
                            </div>
                        </div>

                        <div className="flex items-end gap-4">
                            <div className="text-right">
                                <div className="font-plex-mono text-[10px] font-semibold text-stone uppercase dark:text-fog">
                                    Week total
                                </div>
                                <div
                                    className="font-plex-mono text-[22px] font-semibold"
                                    data-test="burn-total"
                                >
                                    {formatCents(total)}
                                </div>
                            </div>
                            <Button
                                type="submit"
                                disabled={processing || rows.length === 0}
                                className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                data-test="record-burn-week"
                            >
                                {week.recorded
                                    ? 'File correction →'
                                    : 'Record week →'}
                            </Button>
                        </div>
                    </div>

                    <InputError
                        message={errors.week_start}
                        className="px-3 pb-3"
                    />
                </>
            )}
        </Form>
    );
}
