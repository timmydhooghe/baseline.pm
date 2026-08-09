import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import DependencyController from '@/actions/App/Http/Controllers/DependencyController';
import InputError from '@/components/input-error';
import LinkedRecordsField from '@/components/linked-records-field';
import RecordChipList from '@/components/record-chip-list';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { show as dependencyShow } from '@/routes/dependencies';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as dependenciesIndex } from '@/routes/engagements/dependencies';
import type {
    DependencyListItem,
    EngagementPositionSummary,
    EngagementStatus,
    GovernanceOptions,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    dependencies: DependencyListItem[];
    summary: {
        outstanding: number;
        late: number;
        customerOwed: number;
        worstDelayDays: number;
    };
    options: GovernanceOptions;
    position: EngagementPositionSummary;
    can: { create: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const selectClass =
    'h-10 w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 text-[14px] shadow-none outline-none dark:border-paper';

/**
 * The dependency register (FA-20): what the engagement waits for, who owes
 * it, and the delay it has accrued day for day — attributed to the side that
 * owes it.
 */
export default function EngagementDependencies({
    engagement,
    dependencies,
    summary,
    options,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Dependencies', href: dependenciesIndex(engagement.id) },
        ],
        position,
    });

    const [registering, setRegistering] = useState(false);
    const [party, setParty] = useState<'customer' | 'internal'>('customer');

    const stats = [
        {
            label: 'Outstanding',
            value: String(summary.outstanding),
            warn: false,
        },
        { label: 'Late', value: String(summary.late), warn: summary.late > 0 },
        {
            label: 'Worst delay',
            value: `${summary.worstDelayDays} d`,
            warn: summary.worstDelayDays > 0,
        },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Dependencies`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Dependency register
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Every item names the person who owes it and the date
                            it is due. Outstanding items accrue delay day for
                            day, attributed to the side that owes them — and
                            customer-owed items appear on their portal action
                            list.
                        </p>
                    </div>
                    <div
                        className="flex gap-3"
                        data-test="dependencies-summary"
                    >
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={
                                    stat.warn
                                        ? 'border-[1.5px] border-rust px-3 py-2 text-rust'
                                        : 'border-[1.5px] border-ink px-3 py-2 dark:border-paper'
                                }
                            >
                                <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                    {stat.label}
                                </div>
                                <div className="font-plex-mono text-[20px] font-semibold">
                                    {stat.value}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            The register · {summary.customerOwed} owed by the
                            customer
                        </span>
                        {can.create && (
                            <Dialog
                                open={registering}
                                onOpenChange={setRegistering}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="register-dependency"
                                    >
                                        Register a dependency
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-xl">
                                    <DialogTitle>
                                        Register a dependency
                                    </DialogTitle>
                                    <DialogDescription>
                                        Name a person, not a side — "the client"
                                        cannot be chased, and the delay has to
                                        be attributable.
                                    </DialogDescription>
                                    <Form
                                        {...DependencyController.store.form(
                                            engagement.id,
                                        )}
                                        onSuccess={() => setRegistering(false)}
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="title">
                                                        What is needed
                                                    </Label>
                                                    <Input
                                                        id="title"
                                                        name="title"
                                                        required
                                                        placeholder="e.g. Production database credentials"
                                                        className={fieldClass}
                                                        data-test="dependency-title"
                                                    />
                                                    <InputError
                                                        message={errors.title}
                                                    />
                                                </div>
                                                <div className="grid gap-4 sm:grid-cols-2">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="party">
                                                            Owed by
                                                        </Label>
                                                        <select
                                                            id="party"
                                                            name="party"
                                                            value={party}
                                                            onChange={(event) =>
                                                                setParty(
                                                                    event.target
                                                                        .value ===
                                                                        'internal'
                                                                        ? 'internal'
                                                                        : 'customer',
                                                                )
                                                            }
                                                            className={
                                                                selectClass
                                                            }
                                                            data-test="dependency-party"
                                                        >
                                                            <option value="customer">
                                                                Customer
                                                            </option>
                                                            <option value="internal">
                                                                Internal
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="required_on">
                                                            Required by
                                                        </Label>
                                                        <Input
                                                            id="required_on"
                                                            name="required_on"
                                                            type="date"
                                                            required
                                                            className={
                                                                fieldClass
                                                            }
                                                            data-test="dependency-required-on"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.required_on
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                {party === 'customer' ? (
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="responsible_stakeholder_id">
                                                            Responsible contact
                                                        </Label>
                                                        <select
                                                            id="responsible_stakeholder_id"
                                                            name="responsible_stakeholder_id"
                                                            className={
                                                                selectClass
                                                            }
                                                            data-test="dependency-stakeholder"
                                                        >
                                                            <option value="">
                                                                Choose a contact
                                                            </option>
                                                            {(
                                                                options.stakeholders ??
                                                                []
                                                            ).map(
                                                                (
                                                                    stakeholder,
                                                                ) => (
                                                                    <option
                                                                        key={
                                                                            stakeholder.value
                                                                        }
                                                                        value={
                                                                            stakeholder.value
                                                                        }
                                                                    >
                                                                        {
                                                                            stakeholder.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <InputError
                                                            message={
                                                                errors.responsible_stakeholder_id
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="responsible_user_id">
                                                            Responsible
                                                            colleague
                                                        </Label>
                                                        <select
                                                            id="responsible_user_id"
                                                            name="responsible_user_id"
                                                            className={
                                                                selectClass
                                                            }
                                                            data-test="dependency-user"
                                                        >
                                                            <option value="">
                                                                Choose a
                                                                colleague
                                                            </option>
                                                            {options.members.map(
                                                                (member) => (
                                                                    <option
                                                                        key={
                                                                            member.value
                                                                        }
                                                                        value={
                                                                            member.value
                                                                        }
                                                                    >
                                                                        {
                                                                            member.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <InputError
                                                            message={
                                                                errors.responsible_user_id
                                                            }
                                                        />
                                                    </div>
                                                )}
                                                <input
                                                    type="hidden"
                                                    name="visibility"
                                                    value={
                                                        party === 'customer'
                                                            ? 'shared'
                                                            : 'internal'
                                                    }
                                                />
                                                <LinkedRecordsField
                                                    records={options.records}
                                                    label="Blocks"
                                                    testId="dependency-blocks"
                                                    hint="A blocked milestone moves day for day while this stays outstanding."
                                                />
                                                <InputError
                                                    message={errors.links}
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="rounded-none font-semibold shadow-none"
                                                    data-test="submit-dependency"
                                                >
                                                    Register
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>

                    {dependencies.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Nothing on the register yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Item</th>
                                        <th className={tableHeading}>
                                            Owed by
                                        </th>
                                        <th className={tableHeading}>
                                            Required
                                        </th>
                                        <th className={tableHeading}>Delay</th>
                                        <th className={tableHeading}>Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {dependencies.map((dependency) => (
                                        <tr
                                            key={dependency.id}
                                            data-test={`dependency-row-${dependency.id}`}
                                        >
                                            <td className="px-4 py-2">
                                                <div className="flex flex-col gap-1">
                                                    <Link
                                                        href={dependencyShow(
                                                            dependency.id,
                                                        )}
                                                        prefetch
                                                        className="font-medium hover:text-rust"
                                                    >
                                                        {dependency.title}
                                                    </Link>
                                                    {dependency.links.length >
                                                        0 && (
                                                        <RecordChipList
                                                            records={
                                                                dependency.links
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2">
                                                <span className="font-medium">
                                                    {dependency.responsibleName ??
                                                        '—'}
                                                </span>
                                                <span className="block font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                    {dependency.partyLabel}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                {dependency.requiredOn}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2 font-plex-mono font-semibold',
                                                    dependency.late &&
                                                        'text-rust',
                                                )}
                                            >
                                                {dependency.delayDays === 0
                                                    ? '—'
                                                    : `${dependency.delayDays} d`}
                                                {dependency.delayDays > 0 && (
                                                    <span className="block font-plex-mono text-[10px] font-semibold uppercase">
                                                        {
                                                            dependency.attributionLabel
                                                        }
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono text-[11px] uppercase">
                                                {dependency.statusLabel}
                                                <span className="block text-stone dark:text-fog">
                                                    {dependency.eventCount}{' '}
                                                    trail entries
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
