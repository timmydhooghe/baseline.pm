import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import DependencyController from '@/actions/App/Http/Controllers/DependencyController';
import DependencyEventController from '@/actions/App/Http/Controllers/DependencyEventController';
import InputError from '@/components/input-error';
import LinkedRecordsField from '@/components/linked-records-field';
import RecordChipList from '@/components/record-chip-list';
import { Button } from '@/components/ui/button';
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
    DependencyEventView,
    DependencyImpact,
    DependencyView,
    EngagementPositionSummary,
    GovernanceOptions,
    SelectOption,
} from '@/types';

type Props = {
    dependency: DependencyView;
    impact: DependencyImpact[];
    events: DependencyEventView[];
    eventTypes: SelectOption[];
    engagement: { id: string; name: string };
    options: GovernanceOptions;
    position: EngagementPositionSummary;
    can: { update: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const selectClass =
    'h-10 w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 text-[14px] shadow-none outline-none dark:border-paper';

function Panel({
    title,
    children,
    testId,
}: {
    title: string;
    children: React.ReactNode;
    testId?: string;
}) {
    return (
        <div
            className="border-[1.5px] border-ink dark:border-paper"
            data-test={testId}
        >
            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className={sectionLabel}>{title}</span>
            </div>
            <div className="px-4 py-3">{children}</div>
        </div>
    );
}

/**
 * A dependency record (FA-20): who owes it, what it blocks and by how many
 * days, and the append-only evidence trail that makes the attribution
 * defensible rather than merely asserted.
 */
export default function DependenciesShow({
    dependency,
    impact,
    events,
    eventTypes,
    engagement,
    options,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Dependencies', href: dependenciesIndex(engagement.id) },
            { title: dependency.title, href: dependencyShow(dependency.id) },
        ],
        position,
    });

    const [party, setParty] = useState<'customer' | 'internal'>(
        dependency.party,
    );

    return (
        <>
            <Head title={dependency.title} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Dependency · {dependency.statusLabel}
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {dependency.title}
                        </h1>
                        <p className="mt-1 font-plex-mono text-[12px] text-stone uppercase dark:text-fog">
                            Owed by {dependency.responsibleName ?? '—'} ·{' '}
                            {dependency.partyLabel} · required{' '}
                            {dependency.requiredOn}
                            {dependency.settledOn !== null &&
                                ` · settled ${dependency.settledOn}`}
                        </p>
                    </div>
                    <div
                        className={cn(
                            'border-[1.5px] px-3 py-2',
                            dependency.late
                                ? 'border-rust text-rust'
                                : 'border-ink dark:border-paper',
                        )}
                        data-test="dependency-delay"
                    >
                        <div className="font-plex-mono text-[11px] font-semibold uppercase">
                            Delay
                        </div>
                        <div className="font-plex-mono text-[20px] font-semibold">
                            {dependency.delayDays} d
                        </div>
                        <div className="font-plex-mono text-[11px] uppercase">
                            attributed to {dependency.attributionLabel}
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="What is needed">
                        <p className="text-[14px] whitespace-pre-line">
                            {dependency.description ??
                                'No further description recorded.'}
                        </p>
                    </Panel>
                    <Panel title="Blocks" testId="dependency-impact">
                        {impact.length === 0 ? (
                            <RecordChipList
                                records={dependency.links}
                                empty="Nothing linked — a dependency that blocks nothing has no day-for-day consequence."
                            />
                        ) : (
                            <ul className="flex flex-col gap-2 text-[13px]">
                                {impact.map((entry) => (
                                    <li
                                        key={`${entry.record.type}-${entry.record.id}`}
                                    >
                                        <span className="font-medium">
                                            {entry.record.title}
                                        </span>
                                        <span className="text-stone dark:text-fog">
                                            {' '}
                                            · {entry.record.type_label}
                                        </span>
                                        {entry.baseline_date !== null && (
                                            <span className="block font-plex-mono text-[12px]">
                                                {entry.baseline_date} →{' '}
                                                <span
                                                    className={cn(
                                                        dependency.delayDays >
                                                            0 &&
                                                            'font-semibold text-rust',
                                                    )}
                                                >
                                                    {entry.projected_date}
                                                </span>
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                {can.update && (
                    <div
                        className="border-[1.5px] border-ink dark:border-paper"
                        data-test="dependency-edit-form"
                    >
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                {dependency.needsReassignment
                                    ? 'Reassign this dependency'
                                    : 'Edit the register entry'}
                            </span>
                        </div>
                        <Form
                            {...DependencyController.update.form(dependency.id)}
                            className="flex flex-col gap-4 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {dependency.needsReassignment && (
                                        <p className="border-[1.5px] border-rust px-3 py-2 text-[13px] text-rust">
                                            {dependency.responsibleName} is no
                                            longer on record. The item keeps
                                            their name in its history — name
                                            whoever owes it now.
                                        </p>
                                    )}
                                    <div className="grid gap-2">
                                        <Label htmlFor="title">
                                            What is needed
                                        </Label>
                                        <Input
                                            id="title"
                                            name="title"
                                            required
                                            defaultValue={dependency.title}
                                            className={fieldClass}
                                            data-test="edit-title"
                                        />
                                        <InputError message={errors.title} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            Description
                                        </Label>
                                        <Input
                                            id="description"
                                            name="description"
                                            defaultValue={
                                                dependency.description ?? ''
                                            }
                                            className={fieldClass}
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-3">
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
                                                        event.target.value ===
                                                            'internal'
                                                            ? 'internal'
                                                            : 'customer',
                                                    )
                                                }
                                                className={selectClass}
                                                data-test="edit-party"
                                            >
                                                <option value="customer">
                                                    Customer
                                                </option>
                                                <option value="internal">
                                                    Internal
                                                </option>
                                            </select>
                                        </div>
                                        {party === 'customer' ? (
                                            <div className="grid gap-2">
                                                <Label htmlFor="responsible_stakeholder_id">
                                                    Responsible contact
                                                </Label>
                                                <select
                                                    id="responsible_stakeholder_id"
                                                    name="responsible_stakeholder_id"
                                                    defaultValue={
                                                        dependency.responsibleStakeholderId ??
                                                        ''
                                                    }
                                                    className={selectClass}
                                                    data-test="edit-stakeholder"
                                                >
                                                    <option value="">
                                                        Choose a contact
                                                    </option>
                                                    {(
                                                        options.stakeholders ??
                                                        []
                                                    ).map((stakeholder) => (
                                                        <option
                                                            key={
                                                                stakeholder.value
                                                            }
                                                            value={
                                                                stakeholder.value
                                                            }
                                                        >
                                                            {stakeholder.label}
                                                        </option>
                                                    ))}
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
                                                    Responsible colleague
                                                </Label>
                                                <select
                                                    id="responsible_user_id"
                                                    name="responsible_user_id"
                                                    defaultValue={
                                                        dependency.responsibleUserId ??
                                                        ''
                                                    }
                                                    className={selectClass}
                                                    data-test="edit-user"
                                                >
                                                    <option value="">
                                                        Choose a colleague
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
                                                                {member.label}
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
                                        <div className="grid gap-2">
                                            <Label htmlFor="required_on">
                                                Required by
                                            </Label>
                                            <Input
                                                id="required_on"
                                                name="required_on"
                                                type="date"
                                                required
                                                defaultValue={
                                                    dependency.requiredOnDate
                                                }
                                                className={fieldClass}
                                                data-test="edit-required-on"
                                            />
                                            <InputError
                                                message={errors.required_on}
                                            />
                                        </div>
                                    </div>
                                    <input
                                        type="hidden"
                                        name="visibility"
                                        value={
                                            party === 'customer'
                                                ? 'shared'
                                                : dependency.visibility
                                        }
                                    />
                                    <InputError message={errors.visibility} />
                                    <LinkedRecordsField
                                        records={options.records}
                                        defaultSelected={dependency.links}
                                        label="Blocks"
                                    />
                                    <InputError message={errors.links} />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit rounded-none font-semibold shadow-none"
                                        data-test="save-dependency"
                                    >
                                        Save the entry
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {can.update && (
                    <div
                        className="border-[1.5px] border-ink dark:border-paper"
                        data-test="dependency-event-form"
                    >
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Add to the evidence trail
                            </span>
                        </div>
                        <Form
                            {...DependencyEventController.store.form(
                                dependency.id,
                            )}
                            resetOnSuccess
                            className="flex flex-wrap items-end gap-3 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="type">What</Label>
                                        <select
                                            id="type"
                                            name="type"
                                            defaultValue="reminded"
                                            className={cn(selectClass, 'w-44')}
                                            data-test="event-type"
                                        >
                                            {eventTypes.map((type) => (
                                                <option
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.type} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="channel">Channel</Label>
                                        <Input
                                            id="channel"
                                            name="channel"
                                            placeholder="Email, call, steering"
                                            className={cn(fieldClass, 'w-48')}
                                            data-test="event-channel"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="occurred_at">
                                            When
                                        </Label>
                                        <Input
                                            id="occurred_at"
                                            name="occurred_at"
                                            type="date"
                                            className={cn(fieldClass, 'w-44')}
                                            data-test="event-occurred-at"
                                        />
                                        <InputError
                                            message={errors.occurred_at}
                                        />
                                    </div>
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="note">Note</Label>
                                        <Input
                                            id="note"
                                            name="note"
                                            placeholder="What was said, and to whom"
                                            className={fieldClass}
                                            data-test="event-note"
                                        />
                                    </div>
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="evidence_url">
                                            Evidence link
                                        </Label>
                                        <Input
                                            id="evidence_url"
                                            name="evidence_url"
                                            type="url"
                                            placeholder="Link to the email or message"
                                            className={fieldClass}
                                            data-test="event-evidence-url"
                                        />
                                        <InputError
                                            message={errors.evidence_url}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-none font-semibold shadow-none"
                                        data-test="submit-event"
                                    >
                                        Append
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                <Panel title="Evidence trail" testId="dependency-events">
                    {events.length === 0 ? (
                        <p className="text-[13px] text-stone dark:text-fog">
                            Nothing recorded yet. An item that was never chased
                            cannot have its delay attributed.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2 text-[13px]">
                            {events.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex flex-wrap items-baseline gap-2"
                                >
                                    <span className="border-[1.5px] border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase dark:border-paper/40">
                                        {event.typeLabel}
                                    </span>
                                    <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                        {event.occurredAt}
                                        {event.channel !== null &&
                                            ` · ${event.channel}`}
                                        {event.actorName !== null &&
                                            ` · ${event.actorName}`}
                                    </span>
                                    {event.note !== null && (
                                        <span>{event.note}</span>
                                    )}
                                    {event.evidenceUrl !== null && (
                                        <a
                                            href={event.evidenceUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline hover:text-rust"
                                        >
                                            Evidence
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
