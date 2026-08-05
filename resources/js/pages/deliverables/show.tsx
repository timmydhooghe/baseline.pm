import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import DeliverableController from '@/actions/App/Http/Controllers/DeliverableController';
import DeliverableEvidenceController from '@/actions/App/Http/Controllers/DeliverableEvidenceController';
import DeliverableSubmissionController from '@/actions/App/Http/Controllers/DeliverableSubmissionController';
import DeliverableStatusBadge from '@/components/deliverable-status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { show as deliverableShow } from '@/routes/deliverables';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as deliverablesIndex } from '@/routes/engagements/deliverables';
import type {
    DeliverableCriterionView,
    DeliverableEvidenceView,
    DeliverableLinkedWorkView,
    DeliverableMilestoneOption,
    DeliverableResponseView,
    DeliverableVersionView,
    DeliverableView,
    EngagementPositionSummary,
} from '@/types';

type Props = {
    deliverable: DeliverableView;
    criteria: DeliverableCriterionView[];
    evidence: DeliverableEvidenceView[];
    versions: DeliverableVersionView[];
    linkedWork: DeliverableLinkedWorkView[];
    responses: DeliverableResponseView[];
    milestoneOptions: DeliverableMilestoneOption[];
    engagement: { id: string; name: string };
    position: EngagementPositionSummary;
    can: { update: boolean; submit: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClasses =
    'rounded-none border-[1.5px] border-ink bg-transparent px-2 py-1.5 text-[13px] shadow-none outline-none dark:border-paper';

const evidenceKinds = [
    { value: 'release', label: 'Release' },
    { value: 'demo', label: 'Demo' },
    { value: 'test_report', label: 'Test report' },
    { value: 'document', label: 'Document' },
    { value: 'other', label: 'Other' },
];

const confidenceOptions = [
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
];

export default function DeliverablesShow({
    deliverable,
    criteria,
    evidence,
    versions,
    linkedWork,
    responses,
    milestoneOptions,
    engagement,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Deliverables', href: deliverablesIndex(engagement.id) },
            {
                title: deliverable.title,
                href: deliverableShow(deliverable.id),
            },
        ],
        position,
    });

    const [addingEvidence, setAddingEvidence] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const unevidenced = criteria.filter(
        (criterion) => criterion.evidenceId === null,
    ).length;

    return (
        <>
            <Head title={deliverable.title} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Deliverable · baseline v
                            {deliverable.baselineVersion}
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {deliverable.title}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            {deliverable.description ??
                                'No description on the baseline item.'}
                        </p>
                        <p className="mt-1 font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                            {deliverable.clauseReference} ·{' '}
                            {deliverable.ownerName ?? 'No owner'} ·{' '}
                            {deliverable.value?.formatted ?? '—'}
                        </p>
                    </div>
                    <div className="flex flex-col items-end gap-2">
                        <DeliverableStatusBadge
                            status={deliverable.status}
                            label={deliverable.statusLabel}
                            className="text-[12px]"
                        />
                        {deliverable.acceptedValue !== null && (
                            <span className="font-plex-mono text-[12px] font-semibold text-moss">
                                signed {deliverable.acceptedValue.formatted}
                                {deliverable.acceptedAt !== null &&
                                    ` · ${deliverable.acceptedAt}`}
                            </span>
                        )}
                    </div>
                </div>

                {deliverable.status === 'awaiting_acceptance' && (
                    <div
                        className={cn(
                            'border-[1.5px] px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase',
                            deliverable.respondByOverdue
                                ? 'border-rust text-rust'
                                : 'border-ink bg-sun/40 dark:border-paper',
                        )}
                    >
                        {deliverable.respondByOverdue
                            ? `Frozen for customer review — the response deadline of ${deliverable.respondBy} has passed.`
                            : `Frozen for customer review — response due ${deliverable.respondBy}.`}
                    </div>
                )}
                {deliverable.status === 'rejected' && (
                    <div className="border-[1.5px] border-rust px-4 py-3 font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Rejected {deliverable.decidedAt} — rework and resubmit.
                    </div>
                )}

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>Execution record</span>
                        {can.submit && (
                            <Dialog
                                open={submitting}
                                onOpenChange={setSubmitting}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        size="sm"
                                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                        data-test="open-submit-deliverable"
                                    >
                                        Submit for acceptance
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Submit {deliverable.title} for
                                        acceptance
                                    </DialogTitle>
                                    <DialogDescription>
                                        The record and its shared evidence
                                        freeze into an immutable review
                                        snapshot. Customer approvers are
                                        notified and sign off in the portal.
                                        {unevidenced > 0 &&
                                            ' Every criterion needs linked evidence first.'}
                                    </DialogDescription>
                                    <Form
                                        {...DeliverableSubmissionController.store.form(
                                            deliverable.id,
                                        )}
                                        onSuccess={() => setSubmitting(false)}
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="respond-by">
                                                        Respond by
                                                    </Label>
                                                    <Input
                                                        id="respond-by"
                                                        name="respond_by"
                                                        type="date"
                                                        required
                                                        className="rounded-none border-[1.5px] border-ink font-plex-mono shadow-none dark:border-paper"
                                                        data-test="respond-by"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.respond_by
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.criteria
                                                        }
                                                    />
                                                </div>
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button
                                                            variant="secondary"
                                                            type="button"
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        data-test="submit-deliverable"
                                                    >
                                                        Freeze &amp; submit →
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>

                    {can.update ? (
                        <Form
                            {...DeliverableController.update.form(
                                deliverable.id,
                            )}
                            options={{
                                preserveScroll: true,
                                preserveState: true,
                            }}
                            className="flex flex-col gap-5 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="progress">
                                                Progress %
                                            </Label>
                                            <Input
                                                id="progress"
                                                name="progress"
                                                type="number"
                                                min="0"
                                                max="100"
                                                required
                                                defaultValue={
                                                    deliverable.progress
                                                }
                                                className="rounded-none border-[1.5px] border-ink text-right font-plex-mono shadow-none dark:border-paper"
                                                data-test="progress"
                                            />
                                            <InputError
                                                message={errors.progress}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="confidence">
                                                Confidence
                                            </Label>
                                            <select
                                                id="confidence"
                                                name="confidence"
                                                defaultValue={
                                                    deliverable.confidence
                                                }
                                                className={fieldClasses}
                                                data-test="confidence"
                                            >
                                                {confidenceOptions.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={errors.confidence}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="forecast-date">
                                                Forecast date
                                            </Label>
                                            <Input
                                                id="forecast-date"
                                                name="forecast_date"
                                                type="date"
                                                defaultValue={
                                                    deliverable.forecastDate ??
                                                    ''
                                                }
                                                className="rounded-none border-[1.5px] border-ink font-plex-mono shadow-none dark:border-paper"
                                                data-test="forecast-date"
                                            />
                                            <InputError
                                                message={errors.forecast_date}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="milestone">
                                                Milestone
                                            </Label>
                                            <select
                                                id="milestone"
                                                name="milestone_item_id"
                                                defaultValue={
                                                    deliverable.milestoneItemId ??
                                                    ''
                                                }
                                                className={fieldClasses}
                                                data-test="milestone"
                                            >
                                                <option value="">
                                                    Unassigned
                                                </option>
                                                {milestoneOptions.map(
                                                    (milestone) => (
                                                        <option
                                                            key={milestone.id}
                                                            value={milestone.id}
                                                        >
                                                            {milestone.title}
                                                            {milestone.baselineDate !==
                                                                null &&
                                                                ` · ${milestone.baselineDate}`}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <InputError
                                                message={
                                                    errors.milestone_item_id
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-2">
                                        <div className="flex items-center justify-between">
                                            <span className={sectionLabel}>
                                                Acceptance criteria
                                            </span>
                                            <span
                                                className={cn(
                                                    'font-plex-mono text-[12px]',
                                                    unevidenced > 0 &&
                                                        'text-ochre',
                                                )}
                                            >
                                                {criteria.length - unevidenced}/
                                                {criteria.length} evidenced
                                            </span>
                                        </div>
                                        {criteria.length === 0 ? (
                                            <p className="text-[13px] text-stone dark:text-fog">
                                                The baseline item carries no
                                                acceptance criteria — they are
                                                agreed in the baseline, not
                                                here.
                                            </p>
                                        ) : (
                                            criteria.map((criterion, index) => (
                                                <div
                                                    key={`${criterion.criterion}-${index}`}
                                                    className="grid items-start gap-2 border-[1.5px] border-ink/30 px-3 py-2 sm:grid-cols-[1fr_12rem_8rem] dark:border-paper/30"
                                                    data-test={`criterion-${index}`}
                                                >
                                                    <div className="flex flex-col">
                                                        <span className="text-[13px] font-medium">
                                                            {
                                                                criterion.criterion
                                                            }
                                                        </span>
                                                        <span className="text-[12px] text-stone dark:text-fog">
                                                            {criterion.verificationMethod ??
                                                                'No verification method'}
                                                        </span>
                                                    </div>
                                                    <select
                                                        name={`criteria[${index}][evidence_id]`}
                                                        defaultValue={
                                                            criterion.evidenceId ??
                                                            ''
                                                        }
                                                        aria-label={`Evidence for criterion ${index + 1}`}
                                                        className={fieldClasses}
                                                        data-test={`criterion-evidence-${index}`}
                                                    >
                                                        <option value="">
                                                            No evidence
                                                        </option>
                                                        {evidence.map(
                                                            (item) => (
                                                                <option
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    value={
                                                                        item.id
                                                                    }
                                                                >
                                                                    {
                                                                        item.kindLabel
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {item.label}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <select
                                                        name={`criteria[${index}][visibility]`}
                                                        defaultValue={
                                                            criterion.visibility
                                                        }
                                                        aria-label={`Visibility for criterion ${index + 1}`}
                                                        className={fieldClasses}
                                                        data-test={`criterion-visibility-${index}`}
                                                    >
                                                        <option value="shared">
                                                            Shared
                                                        </option>
                                                        <option value="internal">
                                                            Internal
                                                        </option>
                                                    </select>
                                                </div>
                                            ))
                                        )}
                                    </div>

                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                            data-test="save-deliverable"
                                        >
                                            Save record
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    ) : (
                        <dl className="grid gap-4 px-4 py-4 text-[13px] sm:grid-cols-4">
                            <div>
                                <dt className={sectionLabel}>Progress</dt>
                                <dd className="mt-1 font-plex-mono text-[16px] font-semibold">
                                    {deliverable.progress}%
                                </dd>
                            </div>
                            <div>
                                <dt className={sectionLabel}>Confidence</dt>
                                <dd className="mt-1 font-plex-mono text-[16px] font-semibold capitalize">
                                    {deliverable.confidence}
                                </dd>
                            </div>
                            <div>
                                <dt className={sectionLabel}>Forecast</dt>
                                <dd className="mt-1 font-plex-mono text-[16px] font-semibold">
                                    {deliverable.forecastDate ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className={sectionLabel}>Submitted</dt>
                                <dd className="mt-1 font-plex-mono text-[16px] font-semibold">
                                    {deliverable.submittedAt ?? '—'}
                                </dd>
                            </div>
                        </dl>
                    )}
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>Evidence</span>
                        {can.update && (
                            <Dialog
                                open={addingEvidence}
                                onOpenChange={setAddingEvidence}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="add-evidence"
                                    >
                                        Add evidence
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-xl">
                                    <DialogTitle>Add evidence</DialogTitle>
                                    <DialogDescription>
                                        Releases, demos, test reports and
                                        documents that back progress and
                                        acceptance. Internal evidence never
                                        reaches a customer snapshot.
                                    </DialogDescription>
                                    <Form
                                        {...DeliverableEvidenceController.store.form(
                                            deliverable.id,
                                        )}
                                        onSuccess={() =>
                                            setAddingEvidence(false)
                                        }
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-4 sm:grid-cols-2">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="evidence-kind">
                                                            Kind
                                                        </Label>
                                                        <select
                                                            id="evidence-kind"
                                                            name="kind"
                                                            defaultValue="release"
                                                            className={
                                                                fieldClasses
                                                            }
                                                            data-test="evidence-kind"
                                                        >
                                                            {evidenceKinds.map(
                                                                (kind) => (
                                                                    <option
                                                                        key={
                                                                            kind.value
                                                                        }
                                                                        value={
                                                                            kind.value
                                                                        }
                                                                    >
                                                                        {
                                                                            kind.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <InputError
                                                            message={
                                                                errors.kind
                                                            }
                                                        />
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="evidence-visibility">
                                                            Visibility
                                                        </Label>
                                                        <select
                                                            id="evidence-visibility"
                                                            name="visibility"
                                                            defaultValue="shared"
                                                            className={
                                                                fieldClasses
                                                            }
                                                            data-test="evidence-visibility"
                                                        >
                                                            <option value="shared">
                                                                Shared with
                                                                customer
                                                            </option>
                                                            <option value="internal">
                                                                Internal only
                                                            </option>
                                                        </select>
                                                        <InputError
                                                            message={
                                                                errors.visibility
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="evidence-label">
                                                        Label
                                                    </Label>
                                                    <Input
                                                        id="evidence-label"
                                                        name="label"
                                                        required
                                                        placeholder="e.g. UAT round 2 results"
                                                        className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                                        data-test="evidence-label"
                                                    />
                                                    <InputError
                                                        message={errors.label}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="evidence-url">
                                                        Link (optional)
                                                    </Label>
                                                    <Input
                                                        id="evidence-url"
                                                        name="url"
                                                        type="url"
                                                        placeholder="https://"
                                                        className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                                        data-test="evidence-url"
                                                    />
                                                    <InputError
                                                        message={errors.url}
                                                    />
                                                </div>
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button
                                                            variant="secondary"
                                                            type="button"
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        data-test="save-evidence"
                                                    >
                                                        Add evidence →
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                    {evidence.length === 0 ? (
                        <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                            No evidence yet. Acceptance is evidence-backed —
                            every criterion needs a linked item before the
                            record can be submitted.
                        </p>
                    ) : (
                        <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                            {evidence.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-[13px]"
                                    data-test={`evidence-${item.id}`}
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase dark:border-paper/40">
                                            {item.kindLabel}
                                        </span>
                                        {item.url === null ? (
                                            <span className="font-medium">
                                                {item.label}
                                            </span>
                                        ) : (
                                            <a
                                                href={item.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="font-medium underline hover:text-rust"
                                            >
                                                {item.label}
                                            </a>
                                        )}
                                        <span
                                            className={cn(
                                                'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase',
                                                item.visibility === 'shared'
                                                    ? 'border-moss text-moss'
                                                    : 'border-ink/40 text-stone dark:border-paper/40 dark:text-fog',
                                            )}
                                        >
                                            {item.visibilityLabel}
                                        </span>
                                        <span className="text-stone dark:text-fog">
                                            {item.addedByName ?? '—'}
                                            {item.addedAt !== null &&
                                                ` · ${item.addedAt}`}
                                        </span>
                                    </div>
                                    {can.update && (
                                        <Form
                                            {...DeliverableEvidenceController.destroy.form(
                                                [deliverable.id, item.id],
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={processing}
                                                    className="rounded-none border-[1.5px] border-ink/40 font-semibold shadow-none hover:border-rust hover:text-rust dark:border-paper/40"
                                                    data-test={`remove-evidence-${item.id}`}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>Linked work</span>
                        </div>
                        {linkedWork.length === 0 ? (
                            <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                                No work items mapped to this deliverable.
                            </p>
                        ) : (
                            <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                                {linkedWork.map((workItem) => (
                                    <li
                                        key={workItem.id}
                                        className="flex flex-col gap-1 px-4 py-3 text-[13px]"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            {workItem.externalKey !== null && (
                                                <span className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                                    {workItem.externalKey}
                                                </span>
                                            )}
                                            <span className="font-medium">
                                                {workItem.title}
                                            </span>
                                        </div>
                                        <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                            {workItem.stateLabel}
                                            {workItem.classificationLabel !==
                                                null &&
                                                ` · ${workItem.classificationLabel}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Baseline history
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                            {versions.map((version) => (
                                <li
                                    key={version.id}
                                    className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-[13px]"
                                >
                                    <span className="font-plex-mono font-semibold">
                                        v{version.baselineVersion}
                                    </span>
                                    <span className="font-plex-mono">
                                        {version.value?.formatted ?? '—'}
                                    </span>
                                    <span className="text-stone dark:text-fog">
                                        {version.recordedAt}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                {responses.length > 0 && (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Customer decisions
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                            {responses.map((response) => (
                                <li
                                    key={response.id}
                                    className="flex flex-col gap-1 px-4 py-3 text-[13px]"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={cn(
                                                'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase',
                                                response.decision ===
                                                    'accepted' &&
                                                    'border-moss text-moss',
                                                response.decision ===
                                                    'rejected' &&
                                                    'border-rust text-rust',
                                                response.decision ===
                                                    'clarification_requested' &&
                                                    'border-ochre text-ochre',
                                            )}
                                        >
                                            {response.decisionLabel}
                                        </span>
                                        <span className="font-medium">
                                            {response.stakeholderName}
                                        </span>
                                        <span className="text-stone dark:text-fog">
                                            {response.respondedAt}
                                        </span>
                                    </div>
                                    {response.comment !== null && (
                                        <p className="text-stone dark:text-fog">
                                            “{response.comment}”
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    );
}
