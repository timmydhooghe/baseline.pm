import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import WorkItemTriageController from '@/actions/App/Http/Controllers/WorkItemTriageController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { show as changeRequestShow } from '@/routes/change-requests';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as triageShow } from '@/routes/engagements/triage';
import type {
    EngagementPositionSummary,
    EngagementStatus,
    TriageInboxItemView,
    TriagedItemView,
    TriagePricingView,
    WorkItemTriageStatus,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    inbox: TriageInboxItemView[];
    triaged: TriagedItemView[];
    deliverables: { id: string; title: string }[];
    nearestMilestone: {
        title: string;
        date: string | null;
        daysUntil: number | null;
    } | null;
    pricing: TriagePricingView;
    position: EngagementPositionSummary;
    can: { triage: boolean };
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const classificationClasses: Record<WorkItemTriageStatus, string> = {
    existing_scope: 'border-moss text-moss',
    potential_change: 'border-ochre text-ochre',
    operational: 'border-stone text-stone dark:border-fog dark:text-fog',
    dismissed:
        'border-stone/60 text-stone/60 dark:border-fog/60 dark:text-fog/60',
};

const classificationOptions: {
    value: WorkItemTriageStatus;
    title: string;
    description: string;
}[] = [
    {
        value: 'existing_scope',
        title: 'Existing scope',
        description:
            'The work belongs to a contracted deliverable — mapping it absorbs the cost into margin.',
    },
    {
        value: 'potential_change',
        title: 'Potential change',
        description:
            'Out of scope — drafts a change request pre-filled from this item for commercial assessment.',
    },
    {
        value: 'operational',
        title: 'Operational / internal',
        description:
            'Excluded from scope analysis. The explanation is logged and stays on the record.',
    },
    {
        value: 'dismissed',
        title: 'Dismiss',
        description:
            'Removes it from the queue; the classification stays on record.',
    },
];

export default function EngagementsTriage({
    engagement,
    inbox,
    triaged,
    deliverables,
    nearestMilestone,
    pricing,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Triage', href: triageShow(engagement.id) },
        ],
        position,
    });

    const [classifying, setClassifying] = useState<TriageInboxItemView | null>(
        null,
    );
    const [selected, setSelected] =
        useState<WorkItemTriageStatus>('existing_scope');

    const openClassify = (item: TriageInboxItemView) => {
        setSelected('existing_scope');
        setClassifying(item);
    };

    const breachCount = inbox.filter((item) => item.breachRisk).length;

    const stats = [
        {
            label: 'Unresolved',
            value: String(inbox.length),
            warn: inbox.length > 0,
        },
        {
            label: 'Unbilled risk',
            value:
                position.unbilledRisk.price === null
                    ? '—'
                    : position.unbilledRisk.price.formatted +
                      (position.unbilledRisk.unpriced > 0 ? '+' : ''),
            warn: position.unbilledRisk.count > 0,
        },
        {
            label: 'Breach risks',
            value: String(breachCount),
            warn: breachCount > 0,
        },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Triage`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Scope creep triage
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 text-[14px] text-stone dark:text-fog">
                            {!pricing.visible
                                ? 'Commercial figures follow the rate card policy — managing roles see cost and price here.'
                                : pricing.available
                                  ? `Cost ${pricing.costPerDay?.formatted}/day · price ${pricing.sellPerDay?.formatted}/day — blended from baseline v${pricing.baselineVersion} (rate card v${pricing.rateCardVersion}), ${pricing.hoursPerDay}h days.`
                                  : 'No pinned rate card yet — scope creep surfaces unpriced until a baseline carries one.'}
                            {nearestMilestone !== null &&
                                ` Next milestone: ${nearestMilestone.title}${
                                    nearestMilestone.daysUntil !== null
                                        ? ` in ${nearestMilestone.daysUntil}d`
                                        : ''
                                }.`}
                        </p>
                    </div>
                    <div className="flex gap-3" data-test="triage-summary">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={cn(
                                    'border-[1.5px] px-3 py-2',
                                    stat.warn
                                        ? 'border-rust text-rust'
                                        : 'border-ink dark:border-paper',
                                )}
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

                {breachCount > 0 && (
                    <div
                        className="border-[1.5px] border-rust px-4 py-3"
                        data-test="breach-banner"
                    >
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-rust uppercase">
                            {breachCount} {breachCount === 1 ? 'item' : 'items'}{' '}
                            already in motion — work started before a change
                            request was approved is a contractual breach risk.
                        </span>
                    </div>
                )}

                <div
                    className="border-[1.5px] border-ink dark:border-paper"
                    data-test="triage-inbox"
                >
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Triage inbox
                        </span>
                    </div>
                    {inbox.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Inbox zero — every work item is mapped or has a
                            recorded triage decision.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Item</th>
                                        <th className={tableHeading}>Age</th>
                                        <th className={tableHeading}>Logged</th>
                                        <th className={tableHeading}>Effort</th>
                                        <th className={tableHeading}>Cost</th>
                                        <th className={tableHeading}>
                                            Potential price
                                        </th>
                                        <th className={tableHeading}>
                                            Timeline impact
                                        </th>
                                        <th className={tableHeading}>
                                            Nearest deliverable
                                        </th>
                                        <th className={tableHeading} />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {inbox.map((item) => (
                                        <tr
                                            key={item.id}
                                            data-test={`triage-row-${item.id}`}
                                        >
                                            <td className="px-4 py-2">
                                                <div className="flex flex-col gap-1">
                                                    <span className="font-medium">
                                                        {item.externalUrl !==
                                                        null ? (
                                                            <a
                                                                href={
                                                                    item.externalUrl
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="hover:text-rust"
                                                            >
                                                                {item.title}
                                                            </a>
                                                        ) : (
                                                            item.title
                                                        )}
                                                    </span>
                                                    <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                        {item.externalKey !==
                                                        null
                                                            ? `${item.externalKey} · `
                                                            : ''}
                                                        {item.sourceLabel}
                                                        {item.assigneeName !==
                                                        null
                                                            ? ` · ${item.assigneeName}`
                                                            : ''}
                                                    </span>
                                                    {item.breachRisk && (
                                                        <span
                                                            className="w-fit border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap text-rust uppercase"
                                                            data-test={`breach-badge-${item.id}`}
                                                            title={
                                                                item.workStartedAt !==
                                                                null
                                                                    ? `Work started ${item.workStartedAt}`
                                                                    : undefined
                                                            }
                                                        >
                                                            Started before
                                                            approval
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td
                                                className="px-4 py-2 font-plex-mono"
                                                title={
                                                    item.firstSeen ?? undefined
                                                }
                                            >
                                                {item.ageDays}d
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                {item.logged ?? '—'}
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                <div className="flex flex-col">
                                                    <span>
                                                        {item.effortDays !==
                                                        null
                                                            ? `${item.effortDays}d`
                                                            : (item.estimate ??
                                                              '—')}
                                                    </span>
                                                    {item.effortDays !== null &&
                                                        item.estimate !==
                                                            null && (
                                                            <span className="text-[11px] text-stone dark:text-fog">
                                                                est{' '}
                                                                {item.estimate}
                                                            </span>
                                                        )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                {item.cost?.formatted ?? '—'}
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono font-semibold">
                                                {item.price?.formatted ?? '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                {item.timelineImpact ===
                                                null ? (
                                                    <span className="text-stone dark:text-fog">
                                                        —
                                                    </span>
                                                ) : (
                                                    <div className="flex flex-col">
                                                        <span>
                                                            +
                                                            {
                                                                item
                                                                    .timelineImpact
                                                                    .effortDays
                                                            }
                                                            d before{' '}
                                                            {
                                                                item
                                                                    .timelineImpact
                                                                    .milestone
                                                            }
                                                        </span>
                                                        {item.timelineImpact
                                                            .daysUntil !==
                                                            null && (
                                                            <span className="text-[11px] text-stone dark:text-fog">
                                                                due in{' '}
                                                                {
                                                                    item
                                                                        .timelineImpact
                                                                        .daysUntil
                                                                }
                                                                d
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-stone dark:text-fog">
                                                {item.suggestedDeliverable
                                                    ?.title ?? '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                {can.triage && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openClassify(item)
                                                        }
                                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                                        data-test={`classify-${item.id}`}
                                                    >
                                                        Classify
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <div
                    className="border-[1.5px] border-ink dark:border-paper"
                    data-test="triaged-record"
                >
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Decisions on record
                        </span>
                    </div>
                    {triaged.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            No triage decisions yet. Every classification lands
                            here with who took it and when — dismissals
                            included.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Item</th>
                                        <th className={tableHeading}>
                                            Classification
                                        </th>
                                        <th className={tableHeading}>
                                            Recorded
                                        </th>
                                        <th className={tableHeading}>Detail</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {triaged.map((item) => (
                                        <tr
                                            key={item.id}
                                            data-test={`triaged-row-${item.id}`}
                                        >
                                            <td className="px-4 py-2">
                                                <div className="flex flex-col">
                                                    <span className="font-medium">
                                                        {item.title}
                                                    </span>
                                                    <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                        {item.externalKey !==
                                                        null
                                                            ? `${item.externalKey} · `
                                                            : ''}
                                                        {item.sourceLabel}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-2">
                                                <span
                                                    className={cn(
                                                        'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap uppercase',
                                                        classificationClasses[
                                                            item.classification
                                                        ],
                                                    )}
                                                >
                                                    {item.classificationLabel}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-stone dark:text-fog">
                                                {item.triagedByName !== null
                                                    ? `by ${item.triagedByName}`
                                                    : ''}
                                                {item.triagedAt !== null
                                                    ? ` · ${item.triagedAt}`
                                                    : ''}
                                            </td>
                                            <td className="px-4 py-2">
                                                <div className="flex flex-col gap-1">
                                                    {item.deliverableTitle !==
                                                        null && (
                                                        <span>
                                                            Mapped to{' '}
                                                            <span className="font-medium">
                                                                {
                                                                    item.deliverableTitle
                                                                }
                                                            </span>
                                                        </span>
                                                    )}
                                                    {item.changeRequest !==
                                                        null && (
                                                        <span className="flex flex-wrap items-center gap-2">
                                                            <span>
                                                                <Link
                                                                    href={changeRequestShow(
                                                                        item
                                                                            .changeRequest
                                                                            .id,
                                                                    )}
                                                                    prefetch
                                                                    className="font-medium hover:text-rust"
                                                                >
                                                                    {
                                                                        item
                                                                            .changeRequest
                                                                            .title
                                                                    }
                                                                </Link>{' '}
                                                                <span className="text-stone dark:text-fog">
                                                                    (
                                                                    {
                                                                        item
                                                                            .changeRequest
                                                                            .statusLabel
                                                                    }
                                                                    )
                                                                </span>
                                                            </span>
                                                            {item.changeRequest
                                                                .breachRisk && (
                                                                <span className="border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap text-rust uppercase">
                                                                    Started
                                                                    before
                                                                    approval
                                                                </span>
                                                            )}
                                                        </span>
                                                    )}
                                                    {item.note !== null && (
                                                        <span className="text-stone dark:text-fog">
                                                            “{item.note}”
                                                        </span>
                                                    )}
                                                    {item.deliverableTitle ===
                                                        null &&
                                                        item.changeRequest ===
                                                            null &&
                                                        item.note === null && (
                                                            <span className="text-stone dark:text-fog">
                                                                —
                                                            </span>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            <Dialog
                open={classifying !== null}
                onOpenChange={(open) => !open && setClassifying(null)}
            >
                <DialogContent className="sm:max-w-xl">
                    {classifying !== null && (
                        <>
                            <DialogTitle>
                                Classify “{classifying.title}”
                            </DialogTitle>
                            <DialogDescription>
                                The decision is recorded with your name and a
                                timestamp, and stays on the record.
                            </DialogDescription>
                            <Form
                                {...WorkItemTriageController.store.form(
                                    classifying.id,
                                )}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setClassifying(null)}
                                className="flex flex-col gap-4"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <div className="flex flex-col gap-2">
                                            {classificationOptions.map(
                                                (option) => (
                                                    <label
                                                        key={option.value}
                                                        className={cn(
                                                            'flex cursor-pointer items-start gap-3 border-[1.5px] px-3 py-2',
                                                            selected ===
                                                                option.value
                                                                ? 'border-rust'
                                                                : 'border-ink/40 hover:border-ink dark:border-paper/40 dark:hover:border-paper',
                                                        )}
                                                        data-test={`classification-option-${option.value}`}
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="classification"
                                                            value={option.value}
                                                            checked={
                                                                selected ===
                                                                option.value
                                                            }
                                                            onChange={() =>
                                                                setSelected(
                                                                    option.value,
                                                                )
                                                            }
                                                            className="mt-1 accent-rust"
                                                        />
                                                        <span className="flex flex-col">
                                                            <span className="text-[14px] font-semibold">
                                                                {option.title}
                                                            </span>
                                                            <span className="text-[12px] text-stone dark:text-fog">
                                                                {
                                                                    option.description
                                                                }
                                                            </span>
                                                        </span>
                                                    </label>
                                                ),
                                            )}
                                            <InputError
                                                message={
                                                    formErrors.classification
                                                }
                                            />
                                        </div>

                                        {selected === 'existing_scope' && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="triage-deliverable">
                                                    Deliverable that absorbs the
                                                    work
                                                </Label>
                                                <Select
                                                    name="baseline_item_id"
                                                    defaultValue={
                                                        classifying
                                                            .suggestedDeliverable
                                                            ?.id
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="triage-deliverable"
                                                        data-test="triage-deliverable-select"
                                                    >
                                                        <SelectValue placeholder="Pick a deliverable…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {deliverables.map(
                                                            (deliverable) => (
                                                                <SelectItem
                                                                    key={
                                                                        deliverable.id
                                                                    }
                                                                    value={
                                                                        deliverable.id
                                                                    }
                                                                >
                                                                    {
                                                                        deliverable.title
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        formErrors.baseline_item_id
                                                    }
                                                />
                                            </div>
                                        )}

                                        {selected === 'potential_change' && (
                                            <p className="border-[1.5px] border-ochre px-3 py-2 text-[12px] text-stone dark:text-fog">
                                                A draft change request is
                                                created, pre-filled with this
                                                item's effort
                                                {classifying.effortDays !== null
                                                    ? ` (${classifying.effortDays}d)`
                                                    : ''}
                                                {classifying.breachRisk
                                                    ? ' and flagged: work started before approval.'
                                                    : '.'}
                                            </p>
                                        )}

                                        {selected === 'operational' && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="triage-note">
                                                    Why is this operational?
                                                </Label>
                                                <textarea
                                                    id="triage-note"
                                                    name="note"
                                                    required
                                                    rows={3}
                                                    placeholder="e.g. Internal CI maintenance, not client scope."
                                                    className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                    data-test="triage-note"
                                                />
                                                <InputError
                                                    message={formErrors.note}
                                                />
                                            </div>
                                        )}

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
                                                data-test="submit-triage"
                                            >
                                                Record decision →
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
