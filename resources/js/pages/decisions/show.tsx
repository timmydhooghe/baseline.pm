import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import DecisionConfirmationController from '@/actions/App/Http/Controllers/DecisionConfirmationController';
import DecisionController from '@/actions/App/Http/Controllers/DecisionController';
import InputError from '@/components/input-error';
import LinkedRecordsField from '@/components/linked-records-field';
import OptionalSelect, {
    selectTriggerClass,
} from '@/components/optional-select';
import RecordChipList from '@/components/record-chip-list';
import StructuredRowsField from '@/components/structured-rows-field';
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
import { show as decisionShow } from '@/routes/decisions';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as decisionsIndex } from '@/routes/engagements/decisions';
import type {
    DecisionChainEntry,
    DecisionView,
    EngagementPositionSummary,
    GovernanceOptions,
} from '@/types';

type Props = {
    decision: DecisionView;
    chain: DecisionChainEntry[];
    engagement: { id: string; name: string };
    acknowledgementLinks: { stakeholderName: string; url: string }[];
    options: GovernanceOptions;
    position: EngagementPositionSummary;
    can: { update: boolean; confirm: boolean; delete: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const textareaClass =
    'w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[14px] shadow-none outline-none dark:border-paper';

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
 * A decision record (FA-18). A draft is a form; a confirmed record is
 * history, read-only and citable, carrying its supersedes-chain and the
 * customer's acknowledgment.
 */
export default function DecisionsShow({
    decision,
    chain,
    engagement,
    acknowledgementLinks,
    options,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Decisions', href: decisionsIndex(engagement.id) },
            { title: decision.title, href: decisionShow(decision.id) },
        ],
        position,
    });

    const [editing, setEditing] = useState(false);
    const isDraft = decision.status === 'draft';

    return (
        <>
            <Head title={decision.title} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Decision · {decision.statusLabel} ·{' '}
                            {decision.sourceLabel}
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {decision.title}
                        </h1>
                        <p className="mt-1 font-plex-mono text-[12px] text-stone uppercase dark:text-fog">
                            {decision.decidedOn ?? 'No decision date yet'}
                            {decision.decidedByName !== null &&
                                ` · ${decision.decidedByName}`}
                            {' · '}
                            {decision.visibilityLabel}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button
                                variant="outline"
                                onClick={() => setEditing((open) => !open)}
                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                data-test="toggle-edit-decision"
                            >
                                {editing ? 'Cancel edit' : 'Edit draft'}
                            </Button>
                        )}
                        {can.confirm && (
                            <Form
                                {...DecisionConfirmationController.store.form(
                                    decision.id,
                                )}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-none font-semibold shadow-none"
                                        data-test="confirm-decision"
                                    >
                                        Confirm onto the ledger →
                                    </Button>
                                )}
                            </Form>
                        )}
                        {can.delete && (
                            <Form
                                {...DecisionController.destroy.form(
                                    decision.id,
                                )}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="rounded-none border-[1.5px] border-rust font-semibold text-rust shadow-none"
                                        data-test="discard-decision"
                                    >
                                        Discard draft
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                {decision.supersededByTitle !== null && (
                    <div className="border-[1.5px] border-ochre px-4 py-3 font-plex-mono text-[12px] font-semibold text-ochre uppercase">
                        Superseded by {decision.supersededByTitle} — this record
                        stands as history.
                    </div>
                )}

                {editing && can.update ? (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>Edit the draft</span>
                        </div>
                        <Form
                            {...DecisionController.update.form(decision.id)}
                            onSuccess={() => setEditing(false)}
                            className="flex flex-col gap-4 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="title">Title</Label>
                                        <Input
                                            id="title"
                                            name="title"
                                            required
                                            defaultValue={decision.title}
                                            className={fieldClass}
                                            data-test="edit-title"
                                        />
                                        <InputError message={errors.title} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="context">Context</Label>
                                        <textarea
                                            id="context"
                                            name="context"
                                            required
                                            rows={4}
                                            defaultValue={decision.context}
                                            className={textareaClass}
                                        />
                                        <InputError message={errors.context} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="decision">
                                            What was decided
                                        </Label>
                                        <textarea
                                            id="decision"
                                            name="decision"
                                            rows={3}
                                            defaultValue={
                                                decision.decision ?? ''
                                            }
                                            className={textareaClass}
                                            data-test="edit-outcome"
                                        />
                                        <InputError message={errors.decision} />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="decided_on">
                                                Decided on
                                            </Label>
                                            <Input
                                                id="decided_on"
                                                name="decided_on"
                                                type="date"
                                                defaultValue={
                                                    decision.decidedOnDate ?? ''
                                                }
                                                className={fieldClass}
                                                data-test="edit-decided-on"
                                            />
                                            <InputError
                                                message={errors.decided_on}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="decided_by">
                                                Decision owner
                                            </Label>
                                            <OptionalSelect
                                                name="decided_by"
                                                id="decided_by"
                                                options={options.members}
                                                defaultValue={
                                                    decision.decidedById
                                                }
                                                placeholder="Not recorded"
                                                emptyLabel="Not recorded"
                                                testId="edit-decided-by"
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="impact_scope">
                                                Scope impact
                                            </Label>
                                            <Input
                                                id="impact_scope"
                                                name="impact_scope"
                                                defaultValue={
                                                    decision.impact.scope ?? ''
                                                }
                                                className={fieldClass}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="impact_budget">
                                                Budget impact (€)
                                            </Label>
                                            <Input
                                                id="impact_budget"
                                                name="impact_budget"
                                                type="number"
                                                step="0.01"
                                                defaultValue={
                                                    decision.impact.budget ===
                                                    null
                                                        ? ''
                                                        : decision.impact.budget
                                                              .amount / 100
                                                }
                                                className={fieldClass}
                                                data-test="edit-budget"
                                            />
                                            <InputError
                                                message={errors.impact_budget}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="impact_timeline_days">
                                                Timeline impact (days)
                                            </Label>
                                            <Input
                                                id="impact_timeline_days"
                                                name="impact_timeline_days"
                                                type="number"
                                                defaultValue={
                                                    decision.impact
                                                        .timelineDays ?? ''
                                                }
                                                className={fieldClass}
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="visibility">
                                            Visibility
                                        </Label>
                                        <Select
                                            name="visibility"
                                            defaultValue={decision.visibility}
                                        >
                                            <SelectTrigger
                                                id="visibility"
                                                data-test="edit-visibility"
                                                className={selectTriggerClass}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="internal">
                                                    Internal
                                                </SelectItem>
                                                <SelectItem value="shared">
                                                    Shared with the customer
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <p className="text-[12px] text-stone dark:text-fog">
                                            A shared record freezes a
                                            customer-facing snapshot when it is
                                            confirmed. Budget impact never
                                            travels with it.
                                        </p>
                                    </div>
                                    {options.supersedable !== undefined &&
                                        options.supersedable.length > 0 && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="supersedes_id">
                                                    Supersedes
                                                </Label>
                                                <OptionalSelect
                                                    name="supersedes_id"
                                                    id="supersedes_id"
                                                    options={
                                                        options.supersedable ??
                                                        []
                                                    }
                                                    defaultValue={
                                                        decision.supersedesId
                                                    }
                                                    placeholder="Nothing"
                                                    emptyLabel="Nothing"
                                                    testId="edit-supersedes"
                                                />
                                                <InputError
                                                    message={
                                                        errors.supersedes_id
                                                    }
                                                />
                                            </div>
                                        )}
                                    <StructuredRowsField
                                        name="alternatives"
                                        label="Alternatives considered"
                                        addLabel="Add an alternative"
                                        columns={[
                                            {
                                                key: 'option',
                                                label: 'Option',
                                                placeholder:
                                                    'e.g. Build SSO now',
                                                required: true,
                                            },
                                            {
                                                key: 'why_not',
                                                label: 'Why it lost',
                                                placeholder:
                                                    'e.g. Three days we do not have',
                                            },
                                        ]}
                                        defaultRows={decision.alternatives}
                                    />
                                    <StructuredRowsField
                                        name="participants"
                                        label="Participants"
                                        addLabel="Add a participant"
                                        columns={[
                                            {
                                                key: 'name',
                                                label: 'Name',
                                                required: true,
                                            },
                                            {
                                                key: 'affiliation',
                                                label: 'Affiliation',
                                                placeholder:
                                                    'Which side of the table',
                                            },
                                        ]}
                                        defaultRows={decision.participants}
                                    />
                                    <StructuredRowsField
                                        name="evidence"
                                        label="Evidence"
                                        addLabel="Add evidence"
                                        columns={[
                                            {
                                                key: 'label',
                                                label: 'Label',
                                                required: true,
                                            },
                                            {
                                                key: 'url',
                                                label: 'Link',
                                                placeholder: 'https://…',
                                            },
                                        ]}
                                        defaultRows={decision.evidence}
                                    />
                                    <LinkedRecordsField
                                        records={options.records}
                                        defaultSelected={decision.links}
                                    />
                                    <InputError message={errors.links} />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit rounded-none font-semibold shadow-none"
                                        data-test="save-decision"
                                    >
                                        Save draft
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title="Context">
                            <p className="text-[14px] whitespace-pre-line">
                                {decision.context}
                            </p>
                        </Panel>
                        <Panel
                            title="What was decided"
                            testId="decision-outcome"
                        >
                            <p className="text-[14px] whitespace-pre-line">
                                {decision.decision ??
                                    'Not recorded yet — required before this draft can be confirmed.'}
                            </p>
                        </Panel>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title="Alternatives considered">
                        {decision.alternatives.length === 0 ? (
                            <p className="text-[13px] text-stone dark:text-fog">
                                None recorded.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {decision.alternatives.map(
                                    (alternative, index) => (
                                        <li
                                            key={`${alternative.option}-${index}`}
                                            className="text-[13px]"
                                        >
                                            <span className="font-medium">
                                                {alternative.option}
                                            </span>
                                            {alternative.why_not !== null && (
                                                <span className="block text-stone dark:text-fog">
                                                    {alternative.why_not}
                                                </span>
                                            )}
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                    </Panel>
                    <Panel title="Participants">
                        {decision.participants.length === 0 ? (
                            <p className="text-[13px] text-stone dark:text-fog">
                                Nobody recorded.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-1 text-[13px]">
                                {decision.participants.map(
                                    (participant, index) => (
                                        <li
                                            key={`${participant.name}-${index}`}
                                        >
                                            <span className="font-medium">
                                                {participant.name}
                                            </span>
                                            {participant.affiliation !==
                                                null && (
                                                <span className="text-stone dark:text-fog">
                                                    {' '}
                                                    · {participant.affiliation}
                                                </span>
                                            )}
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                    </Panel>
                    <Panel title="Impact" testId="decision-impact">
                        <dl className="flex flex-col gap-2 text-[13px]">
                            <div>
                                <dt className={sectionLabel}>Scope</dt>
                                <dd>{decision.impact.scope ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className={sectionLabel}>Budget</dt>
                                <dd className="font-plex-mono font-semibold">
                                    {decision.impact.budget?.formatted ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className={sectionLabel}>Timeline</dt>
                                <dd className="font-plex-mono font-semibold">
                                    {decision.impact.timelineDays === null
                                        ? '—'
                                        : `${decision.impact.timelineDays > 0 ? '+' : ''}${decision.impact.timelineDays} days`}
                                </dd>
                            </div>
                        </dl>
                    </Panel>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Linked records">
                        <RecordChipList records={decision.links} />
                    </Panel>
                    <Panel title="Evidence">
                        {decision.evidence.length === 0 ? (
                            <p className="text-[13px] text-stone dark:text-fog">
                                No evidence linked.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-1 text-[13px]">
                                {decision.evidence.map((evidence, index) => (
                                    <li key={`${evidence.label}-${index}`}>
                                        {evidence.url === null ? (
                                            <span>{evidence.label}</span>
                                        ) : (
                                            <a
                                                href={evidence.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="underline hover:text-rust"
                                            >
                                                {evidence.label}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                {chain.length > 0 && (
                    <Panel title="Supersedes chain" testId="decision-chain">
                        <ol className="flex flex-col gap-2 text-[13px]">
                            {chain.map((entry) => (
                                <li key={entry.id}>
                                    <Link
                                        href={decisionShow(entry.id)}
                                        className="font-medium hover:text-rust"
                                    >
                                        {entry.title}
                                    </Link>
                                    <span className="text-stone dark:text-fog">
                                        {' '}
                                        · {entry.decidedOn ?? 'undated'} ·{' '}
                                        {entry.statusLabel}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </Panel>
                )}

                {decision.transcriptExcerpt !== null && (
                    <Panel title="Transcript excerpt" testId="decision-excerpt">
                        <pre className="overflow-x-auto font-plex-mono text-[12px] whitespace-pre-wrap text-stone dark:text-fog">
                            {decision.transcriptExcerpt}
                        </pre>
                    </Panel>
                )}

                {decision.visibility === 'shared' && !isDraft && (
                    <Panel
                        title="Customer acknowledgment"
                        testId="decision-acknowledgment"
                    >
                        {decision.acknowledgedAt !== null ? (
                            <p className="text-[13px]">
                                Acknowledged by {decision.acknowledgedByName} on{' '}
                                {decision.acknowledgedAt}.
                                {decision.acknowledgementComment !== null && (
                                    <span className="mt-1 block text-stone dark:text-fog">
                                        “{decision.acknowledgementComment}”
                                    </span>
                                )}
                            </p>
                        ) : (
                            <div className="flex flex-col gap-2">
                                <p className="text-[13px] text-stone dark:text-fog">
                                    Not acknowledged yet. Each link below is
                                    signed for one contact and shows the frozen
                                    record — budget impact is never part of it.
                                </p>
                                <ul className="flex flex-col gap-1 text-[12px]">
                                    {acknowledgementLinks.map((link) => (
                                        <li
                                            key={link.url}
                                            className="flex flex-wrap items-baseline gap-2"
                                        >
                                            <span className="font-medium">
                                                {link.stakeholderName}
                                            </span>
                                            <a
                                                href={link.url}
                                                className="font-plex-mono break-all underline hover:text-rust"
                                            >
                                                {link.url}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </Panel>
                )}
            </div>
        </>
    );
}
