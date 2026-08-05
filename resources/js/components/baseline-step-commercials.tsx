import { Form } from '@inertiajs/react';
import { useState } from 'react';
import BaselineCommercialController from '@/actions/App/Http/Controllers/BaselineCommercialController';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { show as rateCardShow } from '@/routes/organization/rate-card';
import type { BaselineRateCardView, BaselineView } from '@/types';

type Props = {
    baseline: BaselineView;
    rateCard: BaselineRateCardView | null;
    onContinue: () => void;
};

type AllocationRow = {
    key: number;
    baselineItemId: string | null;
    rateCardRoleId: string;
    days: string;
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export function formatCents(cents: number) {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    const major = Math.trunc(abs / 100).toLocaleString('de-DE');
    const minor = String(abs % 100).padStart(2, '0');

    return `${sign}€ ${major},${minor}`;
}

export default function BaselineStepCommercials({
    baseline,
    rateCard,
    onContinue,
}: Props) {
    const [rows, setRows] = useState<AllocationRow[]>(() =>
        baseline.allocations.map((allocation, index) => ({
            key: index,
            baselineItemId: allocation.baselineItemId,
            rateCardRoleId: allocation.rateCardRoleId,
            days: allocation.days,
        })),
    );
    const [nextKey, setNextKey] = useState(baseline.allocations.length);

    const deliverables = baseline.items.filter(
        (item) => item.type === 'deliverable',
    );

    if (rateCard === null) {
        return (
            <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                <div className={sectionLabel}>No rate card yet</div>
                <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                    Cost budgets derive from a published rate card version —
                    never from free-typed amounts.{' '}
                    <TextLink href={rateCardShow()}>
                        Publish your rate card
                    </TextLink>{' '}
                    first, then return here.
                </p>
            </div>
        );
    }

    const roleById = new Map(rateCard.roles.map((role) => [role.id, role]));

    const rowCost = (row: AllocationRow) => {
        const role = roleById.get(row.rateCardRoleId);
        const days = Number.parseFloat(row.days);

        if (role === undefined || Number.isNaN(days)) {
            return 0;
        }

        return Math.round(days * role.costPerDay.amount);
    };

    const addRow = (baselineItemId: string | null) => {
        setRows([
            ...rows,
            {
                key: nextKey,
                baselineItemId,
                rateCardRoleId: rateCard.roles[0]?.id ?? '',
                days: '',
            },
        ]);
        setNextKey(nextKey + 1);
    };

    const updateRow = (key: number, patch: Partial<AllocationRow>) =>
        setRows(
            rows.map((row) => (row.key === key ? { ...row, ...patch } : row)),
        );

    const removeRow = (key: number) =>
        setRows(rows.filter((row) => row.key !== key));

    const directTotal = rows
        .filter((row) => row.baselineItemId !== null)
        .reduce((sum, row) => sum + rowCost(row), 0);
    const managementTotal = rows
        .filter((row) => row.baselineItemId === null)
        .reduce((sum, row) => sum + rowCost(row), 0);
    const costBudget = directTotal + managementTotal;
    const margin = baseline.contractValue.amount - costBudget;
    const marginPercent =
        baseline.contractValue.amount > 0
            ? Math.round((margin / baseline.contractValue.amount) * 100)
            : null;

    const sections: { itemId: string | null; title: string }[] = [
        ...deliverables.map((item) => ({
            itemId: item.id as string | null,
            title: item.title,
        })),
        { itemId: null, title: 'Delivery management (allocated pro-rata)' },
    ];

    return (
        <Form
            {...BaselineCommercialController.update.form(baseline.id)}
            options={{ preserveScroll: true, preserveState: true }}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-2 border-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            Step 4 · Commercials — internal only
                        </span>
                        <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                            Priced with rate card v{rateCard.version}, pinned at
                            creation
                        </span>
                    </div>

                    {deliverables.length === 0 && (
                        <p className="border-[1.5px] border-ink/40 px-4 py-3 text-[13px] text-stone dark:border-paper/40 dark:text-fog">
                            Add deliverables in the structure step to budget
                            them role by role. Delivery management can be
                            estimated already.
                        </p>
                    )}

                    {sections.map((section) => {
                        const sectionRows = rows.filter(
                            (row) => row.baselineItemId === section.itemId,
                        );
                        const sectionCost = sectionRows.reduce(
                            (sum, row) => sum + rowCost(row),
                            0,
                        );

                        return (
                            <div
                                key={section.itemId ?? 'management'}
                                className="border-[1.5px] border-ink dark:border-paper"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                                    <span className="font-medium">
                                        {section.title}
                                    </span>
                                    <span className="font-plex-mono text-[12px]">
                                        {formatCents(sectionCost)}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-2 px-4 py-3">
                                    {sectionRows.map((row) => {
                                        const index = rows.indexOf(row);

                                        return (
                                            <div
                                                key={row.key}
                                                className="grid items-start gap-2 sm:grid-cols-[1fr_8rem_8rem_auto]"
                                            >
                                                <input
                                                    type="hidden"
                                                    name={`allocations[${index}][baseline_item_id]`}
                                                    value={
                                                        row.baselineItemId ?? ''
                                                    }
                                                />
                                                <div>
                                                    <Select
                                                        name={`allocations[${index}][rate_card_role_id]`}
                                                        value={
                                                            row.rateCardRoleId
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            updateRow(row.key, {
                                                                rateCardRoleId:
                                                                    value,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label={`Role for line ${index + 1}`}
                                                        >
                                                            <SelectValue placeholder="Role" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {rateCard.roles.map(
                                                                (role) => (
                                                                    <SelectItem
                                                                        key={
                                                                            role.id
                                                                        }
                                                                        value={
                                                                            role.id
                                                                        }
                                                                    >
                                                                        {
                                                                            role.name
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `allocations.${index}.rate_card_role_id`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Input
                                                        name={`allocations[${index}][days]`}
                                                        type="number"
                                                        step="0.25"
                                                        min="0.25"
                                                        required
                                                        value={row.days}
                                                        onChange={(event) =>
                                                            updateRow(row.key, {
                                                                days: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        placeholder="Days"
                                                        aria-label={`Days for line ${index + 1}`}
                                                        className="rounded-none border-[1.5px] border-ink text-right font-plex-mono shadow-none dark:border-paper"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `allocations.${index}.days`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <span className="pt-2 text-right font-plex-mono text-[12px] text-stone dark:text-fog">
                                                    {formatCents(rowCost(row))}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                    onClick={() =>
                                                        removeRow(row.key)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        );
                                    })}
                                    <div>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            className="rounded-none shadow-none"
                                            onClick={() =>
                                                addRow(section.itemId)
                                            }
                                        >
                                            Add role
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        );
                    })}

                    <InputError message={errors.allocations} />

                    <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-3 dark:border-paper">
                        <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                            <div className={sectionLabel}>Cost budget</div>
                            <div className="mt-1 font-plex-mono text-[18px] font-bold">
                                {formatCents(costBudget)}
                            </div>
                        </div>
                        <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                            <div className={sectionLabel}>Contract value</div>
                            <div className="mt-1 font-plex-mono text-[18px] font-bold">
                                {baseline.contractValue.formatted}
                            </div>
                        </div>
                        <div className="px-4 py-3">
                            <div className={sectionLabel}>Planned margin</div>
                            <div
                                className={`mt-1 font-plex-mono text-[18px] font-bold ${margin < 0 ? 'text-rust' : ''}`}
                            >
                                {formatCents(margin)}
                                {marginPercent !== null &&
                                    ` · ${marginPercent}%`}
                            </div>
                        </div>
                    </div>

                    <p className="text-[12px] text-stone dark:text-fog">
                        Cost and margin are derived from the pinned rate card
                        and locked into the baseline on approval. They never
                        appear in the portal or anything the customer sees.
                    </p>

                    <div className="flex justify-between gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            variant="secondary"
                            className="rounded-none shadow-none"
                            data-test="baseline-commercials-save"
                        >
                            Save role mix
                        </Button>
                        <Button
                            type="button"
                            onClick={onContinue}
                            className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                            data-test="baseline-commercials-continue"
                        >
                            Continue →
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
