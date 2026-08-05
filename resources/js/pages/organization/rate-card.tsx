import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import RateCardController from '@/actions/App/Http/Controllers/RateCardController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { show as organization } from '@/routes/organization';
import { show as rateCard } from '@/routes/organization/rate-card';
import type { Money } from '@/types';

type RateCardRoleView = {
    id: string;
    name: string;
    costPerDay: Money;
    sellPerDay: Money;
};

type RateCardVersionView = {
    id: string;
    version: number;
    publishedAt: string | null;
    publishedBy: string | null;
    roles: RateCardRoleView[];
};

type Props = {
    versions: RateCardVersionView[];
    can: { manage: boolean };
};

type EditorRow = {
    key: number;
    name: string;
    costPerDay: string;
    sellPerDay: string;
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const rateInput =
    'w-32 rounded-none border-[1.5px] border-ink text-right font-plex-mono shadow-none dark:border-paper';

function marginLabel(role: RateCardRoleView) {
    if (role.sellPerDay.amount <= 0) {
        return '—';
    }

    const margin =
        ((role.sellPerDay.amount - role.costPerDay.amount) /
            role.sellPerDay.amount) *
        100;

    return `${Math.round(margin)}%`;
}

function euros(money: Money) {
    return String(money.amount / 100);
}

function RolesTable({ roles }: { roles: RateCardRoleView[] }) {
    return (
        <table className="w-full text-left text-[14px]">
            <thead>
                <tr className="border-b-[1.5px] border-ink dark:border-paper">
                    <th className={tableHeading}>Role</th>
                    <th className={cn(tableHeading, 'text-right')}>Cost/day</th>
                    <th className={cn(tableHeading, 'text-right')}>Sell/day</th>
                    <th className={cn(tableHeading, 'text-right')}>Margin</th>
                </tr>
            </thead>
            <tbody>
                {roles.map((role, roleIndex) => (
                    <tr
                        key={role.id}
                        className={cn(
                            roleIndex < roles.length - 1 &&
                                'border-b border-ink/20 dark:border-paper/20',
                        )}
                    >
                        <td className="px-4 py-3 font-medium">{role.name}</td>
                        <td className="px-4 py-3 text-right font-plex-mono text-[13px]">
                            {role.costPerDay.formatted}
                        </td>
                        <td className="px-4 py-3 text-right font-plex-mono text-[13px]">
                            {role.sellPerDay.formatted}
                        </td>
                        <td className="px-4 py-3 text-right font-plex-mono text-[13px] text-stone dark:text-fog">
                            {marginLabel(role)}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function OrganizationRateCard({ versions, can }: Props) {
    const current = versions.at(0) ?? null;
    const history = versions.slice(1);

    const [isEditing, setIsEditing] = useState(false);
    const [nextRowKey, setNextRowKey] = useState(0);
    const [rows, setRows] = useState<EditorRow[]>([]);

    const startEditing = () => {
        const prefilled = current
            ? current.roles.map((role, index) => ({
                  key: index,
                  name: role.name,
                  costPerDay: euros(role.costPerDay),
                  sellPerDay: euros(role.sellPerDay),
              }))
            : [{ key: 0, name: '', costPerDay: '', sellPerDay: '' }];

        setRows(prefilled);
        setNextRowKey(prefilled.length);
        setIsEditing(true);
    };

    const addRow = () => {
        setRows([
            ...rows,
            { key: nextRowKey, name: '', costPerDay: '', sellPerDay: '' },
        ]);
        setNextRowKey(nextRowKey + 1);
    };

    const removeRow = (key: number) => {
        setRows(rows.filter((row) => row.key !== key));
    };

    return (
        <>
            <Head title="Rate card" />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Organization · internal only
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            Rate card
                        </h1>
                        <p className="mt-2 max-w-xl text-[13px] text-stone dark:text-fog">
                            Role-level cost and sell rates per day. Every
                            baseline pins the version it was priced with, so
                            publishing new rates never rewrites history — and
                            rates never appear in the portal or in anything a
                            customer sees.
                        </p>
                    </div>

                    {can.manage && !isEditing && (
                        <Button
                            className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                            data-test="new-rate-card-version-button"
                            onClick={startEditing}
                        >
                            {current ? 'New version' : 'Create rate card'}
                        </Button>
                    )}
                </div>

                {isEditing && (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                {current
                                    ? `Publish version ${current.version + 1}`
                                    : 'Publish version 1'}
                            </span>
                        </div>
                        <Form
                            {...RateCardController.store.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setIsEditing(false)}
                        >
                            {({ processing, errors }) => (
                                <div className="flex flex-col gap-4 px-4 py-4">
                                    <table className="w-full text-left text-[14px]">
                                        <thead>
                                            <tr className="border-b border-ink/20 dark:border-paper/20">
                                                <th
                                                    className={cn(
                                                        tableHeading,
                                                        'pl-0',
                                                    )}
                                                >
                                                    Role
                                                </th>
                                                <th className={tableHeading}>
                                                    Cost/day (€)
                                                </th>
                                                <th className={tableHeading}>
                                                    Sell/day (€)
                                                </th>
                                                <th className={tableHeading}>
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.map((row, index) => (
                                                <tr
                                                    key={row.key}
                                                    className="align-top"
                                                >
                                                    <td className="py-2 pr-4">
                                                        <Input
                                                            name={`roles[${index}][name]`}
                                                            defaultValue={
                                                                row.name
                                                            }
                                                            required
                                                            placeholder="Senior developer"
                                                            aria-label={`Name of role ${index + 1}`}
                                                            className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    `roles.${index}.name`
                                                                ]
                                                            }
                                                        />
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        <Input
                                                            name={`roles[${index}][cost_per_day]`}
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            defaultValue={
                                                                row.costPerDay
                                                            }
                                                            required
                                                            placeholder="450"
                                                            aria-label={`Cost per day of role ${index + 1}`}
                                                            className={
                                                                rateInput
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    `roles.${index}.cost_per_day`
                                                                ]
                                                            }
                                                        />
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        <Input
                                                            name={`roles[${index}][sell_per_day]`}
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            defaultValue={
                                                                row.sellPerDay
                                                            }
                                                            required
                                                            placeholder="780"
                                                            aria-label={`Sell per day of role ${index + 1}`}
                                                            className={
                                                                rateInput
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    `roles.${index}.sell_per_day`
                                                                ]
                                                            }
                                                        />
                                                    </td>
                                                    <td className="py-2 text-right">
                                                        {rows.length > 1 && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                                onClick={() =>
                                                                    removeRow(
                                                                        row.key,
                                                                    )
                                                                }
                                                            >
                                                                Remove
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>

                                    <InputError message={errors.roles} />

                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="rounded-none shadow-none"
                                            onClick={addRow}
                                        >
                                            Add role
                                        </Button>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                className="rounded-none shadow-none"
                                                onClick={() =>
                                                    setIsEditing(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                                data-test="publish-rate-card-button"
                                            >
                                                {current
                                                    ? `Publish v${current.version + 1}`
                                                    : 'Publish v1'}
                                            </Button>
                                        </div>
                                    </div>

                                    <p className="text-[12px] text-stone dark:text-fog">
                                        Publishing creates a new immutable
                                        version. Baselines priced with earlier
                                        versions keep their rates.
                                    </p>
                                </div>
                            )}
                        </Form>
                    </div>
                )}

                {current ? (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="flex flex-wrap items-baseline justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Current · v{current.version}
                            </span>
                            <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                Published {current.publishedAt}
                                {current.publishedBy
                                    ? ` by ${current.publishedBy}`
                                    : ''}
                            </span>
                        </div>
                        <RolesTable roles={current.roles} />
                    </div>
                ) : (
                    !isEditing && (
                        <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                            <div className={sectionLabel}>No rate card yet</div>
                            <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                                Define cost and sell rates per role to price
                                baselines and change requests. Cost and margin
                                always derive from a published version — never
                                from free-typed amounts.
                            </p>
                        </div>
                    )
                )}

                {history.length > 0 && (
                    <div className="flex flex-col gap-3">
                        <span className={sectionLabel}>
                            Version history · {history.length}
                        </span>
                        {history.map((version) => (
                            <details
                                key={version.id}
                                className="border-[1.5px] border-ink dark:border-paper"
                            >
                                <summary className="flex cursor-pointer flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                                    <span className={sectionLabel}>
                                        v{version.version}
                                    </span>
                                    <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                        Published {version.publishedAt}
                                        {version.publishedBy
                                            ? ` by ${version.publishedBy}`
                                            : ''}
                                    </span>
                                </summary>
                                <div className="border-t-[1.5px] border-ink dark:border-paper">
                                    <RolesTable roles={version.roles} />
                                </div>
                            </details>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

OrganizationRateCard.layout = {
    breadcrumbs: [
        {
            title: 'Organization',
            href: organization(),
        },
        {
            title: 'Rate card',
            href: rateCard(),
        },
    ],
};
