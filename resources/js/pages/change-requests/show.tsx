import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import ChangeRequestAssessmentController from '@/actions/App/Http/Controllers/ChangeRequestAssessmentController';
import ChangeRequestController from '@/actions/App/Http/Controllers/ChangeRequestController';
import ChangeRequestProposalController from '@/actions/App/Http/Controllers/ChangeRequestProposalController';
import ChangeRequestSubmissionController from '@/actions/App/Http/Controllers/ChangeRequestSubmissionController';
import { formatCents } from '@/components/baseline-step-commercials';
import ChangeRequestStatusBadge from '@/components/change-request-status-badge';
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
import { show as baselineShow } from '@/routes/engagements/baseline';
import { index as changeRequestsIndex } from '@/routes/engagements/change-requests';
import type {
    ChangeRequestAssessmentView,
    ChangeRequestBaselineItem,
    ChangeRequestResponseView,
    ChangeRequestRoleOption,
    ChangeRequestView,
    EngagementPositionSummary,
    EngagementStatus,
} from '@/types';

type Props = {
    changeRequest: ChangeRequestView;
    assessment: ChangeRequestAssessmentView;
    roles: ChangeRequestRoleOption[];
    baselineItems: ChangeRequestBaselineItem[];
    responses: ChangeRequestResponseView[];
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    baselineVersion: number | null;
    position: EngagementPositionSummary;
    can: {
        update: boolean;
        viewCommercials: boolean;
        startAssessment: boolean;
        moveToProposal: boolean;
        submit: boolean;
    };
};

type AllocationRow = {
    key: number;
    rateCardRoleId: string;
    days: string;
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const cardClasses = 'border-[1.5px] border-ink dark:border-paper';
const cardHeaderClasses =
    'flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper';

const originOptions = [
    { value: 'steering_call', label: 'Steering call' },
    { value: 'email', label: 'Email' },
    { value: 'meeting', label: 'Meeting' },
    { value: 'other', label: 'Other' },
];

export default function ChangeRequestShow({
    changeRequest,
    assessment,
    roles,
    baselineItems,
    responses,
    engagement,
    baselineVersion,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            {
                title: 'Change requests',
                href: changeRequestsIndex(engagement.id),
            },
            {
                title: changeRequest.title,
                href: changeRequestShow(changeRequest.id),
            },
        ],
        position,
    });

    const assessmentEditable =
        can.update &&
        (changeRequest.status === 'under_assessment' ||
            changeRequest.status === 'customer_proposal');

    const [editing, setEditing] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [rows, setRows] = useState<AllocationRow[]>(() =>
        assessment.allocations.length === 0 &&
        changeRequest.estimatedDays !== null
            ? [
                  // Prefill from the drift evidence: the greater of the
                  // provider estimate and logged time, waiting for a role.
                  {
                      key: 0,
                      rateCardRoleId: '',
                      days: String(changeRequest.estimatedDays),
                  },
              ]
            : assessment.allocations.map((allocation, index) => ({
                  key: index,
                  rateCardRoleId: allocation.rateCardRoleId,
                  days: allocation.days,
              })),
    );
    const [nextKey, setNextKey] = useState(rows.length);
    const [affected, setAffected] = useState<Set<string>>(
        () => new Set(assessment.affectedItemIds),
    );
    const [impactMilestone, setImpactMilestone] = useState<string>(
        changeRequest.impactMilestoneId ?? 'none',
    );
    const [priceInput, setPriceInput] = useState<string>(
        changeRequest.customerPrice !== null
            ? String(changeRequest.customerPrice.amount / 100)
            : '',
    );

    const roleById = new Map(roles.map((role) => [role.id, role]));
    const milestones = baselineItems.filter(
        (item) => item.type === 'milestone',
    );

    const rowCost = (row: AllocationRow) => {
        const role = roleById.get(row.rateCardRoleId);
        const days = Number.parseFloat(row.days);

        if (role === undefined || Number.isNaN(days)) {
            return 0;
        }

        return Math.round(days * role.costPerDay.amount);
    };

    const rowSuggested = (row: AllocationRow) => {
        const role = roleById.get(row.rateCardRoleId);
        const days = Number.parseFloat(row.days);

        if (role === undefined || Number.isNaN(days)) {
            return 0;
        }

        return Math.round(days * role.sellPerDay.amount);
    };

    const liveCost = rows.reduce((sum, row) => sum + rowCost(row), 0);
    const liveSuggested = rows.reduce((sum, row) => sum + rowSuggested(row), 0);

    const priceCents = Math.round(Number.parseFloat(priceInput || '0') * 100);
    const liveMargin = Number.isNaN(priceCents) ? null : priceCents - liveCost;

    const toggleAffected = (id: string) => {
        const next = new Set(affected);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        setAffected(next);
    };

    const evidence = [
        changeRequest.estimatedDays !== null
            ? `~${changeRequest.estimatedDays}d effort`
            : null,
        changeRequest.loggedHours !== null
            ? `${changeRequest.loggedHours}h logged`
            : null,
        changeRequest.workStartedAt !== null
            ? `work started ${changeRequest.workStartedAt}`
            : null,
    ].filter((line): line is string => line !== null);

    return (
        <>
            <Head title={changeRequest.title} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Change request
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {changeRequest.title}
                        </h1>
                        <p className="mt-1 text-[13px] text-stone dark:text-fog">
                            {changeRequest.originLabel ?? 'Unknown origin'}
                            {changeRequest.workItem !== null && (
                                <>
                                    {' · '}
                                    {changeRequest.workItem.externalUrl !==
                                    null ? (
                                        <a
                                            href={
                                                changeRequest.workItem
                                                    .externalUrl
                                            }
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline hover:text-rust"
                                        >
                                            {changeRequest.workItem
                                                .externalKey ??
                                                changeRequest.workItem.title}
                                        </a>
                                    ) : (
                                        (changeRequest.workItem.externalKey ??
                                        changeRequest.workItem.title)
                                    )}
                                </>
                            )}
                            {changeRequest.createdByName !== null &&
                                ` · raised by ${changeRequest.createdByName}`}
                            {changeRequest.createdAt !== null &&
                                ` · ${changeRequest.createdAt}`}
                            {evidence.length > 0 &&
                                ` · ${evidence.join(' · ')}`}
                        </p>
                    </div>
                    <div className="flex flex-col items-end gap-2">
                        <ChangeRequestStatusBadge
                            status={changeRequest.status}
                            label={changeRequest.statusLabel}
                        />
                        {changeRequest.breachRisk && (
                            <span className="border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap text-rust uppercase">
                                Started before approval
                            </span>
                        )}
                    </div>
                </div>

                {changeRequest.status === 'awaiting_approval' && (
                    <div
                        className="border-[1.5px] border-ink bg-sun/40 px-4 py-3 dark:border-paper"
                        data-test="awaiting-banner"
                    >
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                            Frozen for customer decision — submitted{' '}
                            {changeRequest.submittedAt}, respond by{' '}
                            {changeRequest.respondBy ?? '—'}
                            {changeRequest.respondByOverdue && (
                                <span className="text-rust"> · overdue</span>
                            )}
                        </span>
                    </div>
                )}

                {changeRequest.status === 'approved' && (
                    <div
                        className="border-[1.5px] border-moss px-4 py-3 text-moss"
                        data-test="approved-banner"
                    >
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                            Approved {changeRequest.decidedAt} at{' '}
                            {changeRequest.customerPrice?.formatted ?? '—'} —
                            minted{' '}
                            <Link
                                href={baselineShow(engagement.id)}
                                className="underline"
                            >
                                baseline v
                                {changeRequest.mintedBaselineVersion ?? '?'}
                            </Link>
                            .
                        </span>
                    </div>
                )}

                {changeRequest.status === 'rejected' && (
                    <div
                        className="border-[1.5px] border-rust px-4 py-3 text-rust"
                        data-test="rejected-banner"
                    >
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                            Rejected {changeRequest.decidedAt} — the decision is
                            on record below.
                        </span>
                    </div>
                )}

                <div className={cardClasses}>
                    <div className={cardHeaderClasses}>
                        <span className={sectionLabel}>What & why</span>
                        {can.update && (
                            <Dialog open={editing} onOpenChange={setEditing}>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="edit-change-request"
                                    >
                                        Edit
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-xl">
                                    <DialogTitle>
                                        Edit change request
                                    </DialogTitle>
                                    <DialogDescription>
                                        The narrative of the change — structured
                                        commercial terms live in the assessment.
                                    </DialogDescription>
                                    <Form
                                        {...ChangeRequestController.update.form(
                                            changeRequest.id,
                                        )}
                                        onSuccess={() => setEditing(false)}
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-title">
                                                        Title
                                                    </Label>
                                                    <Input
                                                        id="edit-title"
                                                        name="title"
                                                        required
                                                        defaultValue={
                                                            changeRequest.title
                                                        }
                                                        className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                                    />
                                                    <InputError
                                                        message={errors.title}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-what">
                                                        What changes?
                                                    </Label>
                                                    <textarea
                                                        id="edit-what"
                                                        name="what"
                                                        required
                                                        rows={3}
                                                        defaultValue={
                                                            changeRequest.what
                                                        }
                                                        className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                    />
                                                    <InputError
                                                        message={errors.what}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-why">
                                                        Why now?
                                                    </Label>
                                                    <textarea
                                                        id="edit-why"
                                                        name="why"
                                                        rows={2}
                                                        defaultValue={
                                                            changeRequest.why ??
                                                            ''
                                                        }
                                                        className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                    />
                                                    <InputError
                                                        message={errors.why}
                                                    />
                                                </div>
                                                {changeRequest.origin ===
                                                'drift' ? (
                                                    <input
                                                        type="hidden"
                                                        name="origin"
                                                        value="drift"
                                                    />
                                                ) : (
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-origin">
                                                            Origin
                                                        </Label>
                                                        <Select
                                                            name="origin"
                                                            defaultValue={
                                                                changeRequest.origin ??
                                                                'other'
                                                            }
                                                        >
                                                            <SelectTrigger id="edit-origin">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {originOptions.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                option.value
                                                                            }
                                                                            value={
                                                                                option.value
                                                                            }
                                                                        >
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                        <InputError
                                                            message={
                                                                errors.origin
                                                            }
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
                                                    >
                                                        Save
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                    <div className="flex flex-col gap-3 px-4 py-3 text-[14px]">
                        <p>{changeRequest.what}</p>
                        {changeRequest.why !== null && (
                            <p className="text-stone dark:text-fog">
                                {changeRequest.why}
                            </p>
                        )}
                    </div>
                </div>

                {changeRequest.status === 'draft' ? (
                    <div className="flex flex-wrap items-center gap-3">
                        {can.startAssessment && (
                            <Form
                                {...ChangeRequestController.transition.form(
                                    changeRequest.id,
                                )}
                            >
                                {({ processing, errors }) => (
                                    <div className="flex flex-col gap-1">
                                        <input
                                            type="hidden"
                                            name="status"
                                            value="under_assessment"
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                            data-test="start-assessment"
                                        >
                                            Start assessment →
                                        </Button>
                                        <InputError message={errors.status} />
                                    </div>
                                )}
                            </Form>
                        )}
                        <p className="max-w-md text-[12px] text-stone dark:text-fog">
                            Assessment pins the rate card version the approved
                            baseline was priced with — every derived number
                            traces to it.
                        </p>
                    </div>
                ) : (
                    <div className={cardClasses} data-test="assessment-card">
                        <div className={cardHeaderClasses}>
                            <span className={sectionLabel}>
                                Structured assessment
                            </span>
                            <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                {changeRequest.rateCardVersion !== null
                                    ? `Priced with rate card v${changeRequest.rateCardVersion}, pinned at assessment`
                                    : 'No rate card pinned yet'}
                                {baselineVersion !== null &&
                                    ` · against baseline v${baselineVersion}`}
                            </span>
                        </div>
                        {assessmentEditable ? (
                            <Form
                                {...ChangeRequestAssessmentController.update.form(
                                    changeRequest.id,
                                )}
                                options={{
                                    preserveScroll: true,
                                    preserveState: true,
                                }}
                                className="flex flex-col gap-5 px-4 py-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="flex flex-col gap-2">
                                            <div className="flex items-center justify-between">
                                                <span className={sectionLabel}>
                                                    Effort as role mix
                                                </span>
                                                <span className="font-plex-mono text-[12px]">
                                                    {formatCents(liveCost)}{' '}
                                                    derived
                                                </span>
                                            </div>
                                            {rows.map((row, index) => (
                                                <div
                                                    key={row.key}
                                                    className="grid items-start gap-2 sm:grid-cols-[1fr_8rem_8rem_auto]"
                                                >
                                                    <div>
                                                        <Select
                                                            name={`allocations[${index}][rate_card_role_id]`}
                                                            value={
                                                                row.rateCardRoleId
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                setRows(
                                                                    rows.map(
                                                                        (
                                                                            other,
                                                                        ) =>
                                                                            other.key ===
                                                                            row.key
                                                                                ? {
                                                                                      ...other,
                                                                                      rateCardRoleId:
                                                                                          value,
                                                                                  }
                                                                                : other,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                aria-label={`Role for line ${index + 1}`}
                                                            >
                                                                <SelectValue placeholder="Role" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {roles.map(
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
                                                                setRows(
                                                                    rows.map(
                                                                        (
                                                                            other,
                                                                        ) =>
                                                                            other.key ===
                                                                            row.key
                                                                                ? {
                                                                                      ...other,
                                                                                      days: event
                                                                                          .target
                                                                                          .value,
                                                                                  }
                                                                                : other,
                                                                    ),
                                                                )
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
                                                        {formatCents(
                                                            rowCost(row),
                                                        )}
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                        onClick={() =>
                                                            setRows(
                                                                rows.filter(
                                                                    (other) =>
                                                                        other.key !==
                                                                        row.key,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            ))}
                                            <div>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    size="sm"
                                                    className="rounded-none shadow-none"
                                                    data-test="add-role-line"
                                                    onClick={() => {
                                                        setRows([
                                                            ...rows,
                                                            {
                                                                key: nextKey,
                                                                rateCardRoleId:
                                                                    roles[0]
                                                                        ?.id ??
                                                                    '',
                                                                days: '',
                                                            },
                                                        ]);
                                                        setNextKey(nextKey + 1);
                                                    }}
                                                >
                                                    Add role
                                                </Button>
                                            </div>
                                            <InputError
                                                message={errors.allocations}
                                            />
                                        </div>

                                        <div className="flex flex-col gap-2">
                                            <span className={sectionLabel}>
                                                Affected items
                                            </span>
                                            {baselineItems.length === 0 ? (
                                                <p className="text-[13px] text-stone dark:text-fog">
                                                    The approved baseline has no
                                                    items to link.
                                                </p>
                                            ) : (
                                                <div className="grid gap-1 sm:grid-cols-2">
                                                    {baselineItems.map(
                                                        (item) => (
                                                            <label
                                                                key={item.id}
                                                                className={cn(
                                                                    'flex cursor-pointer items-center gap-2 border-[1.5px] px-2 py-1.5 text-[13px]',
                                                                    affected.has(
                                                                        item.id,
                                                                    )
                                                                        ? 'border-rust'
                                                                        : 'border-ink/30 dark:border-paper/30',
                                                                )}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    name="affected_items[]"
                                                                    value={
                                                                        item.id
                                                                    }
                                                                    checked={affected.has(
                                                                        item.id,
                                                                    )}
                                                                    onChange={() =>
                                                                        toggleAffected(
                                                                            item.id,
                                                                        )
                                                                    }
                                                                    className="accent-rust"
                                                                />
                                                                <span className="font-plex-mono text-[10px] text-stone uppercase dark:text-fog">
                                                                    {
                                                                        item.typeLabel
                                                                    }
                                                                </span>
                                                                <span>
                                                                    {item.title}
                                                                </span>
                                                            </label>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex flex-col gap-2">
                                            <span className={sectionLabel}>
                                                Schedule impact — structured
                                            </span>
                                            <div className="grid items-start gap-2 sm:grid-cols-[1fr_8rem]">
                                                <div>
                                                    <input
                                                        type="hidden"
                                                        name="impact_milestone_id"
                                                        value={
                                                            impactMilestone ===
                                                            'none'
                                                                ? ''
                                                                : impactMilestone
                                                        }
                                                    />
                                                    <Select
                                                        value={impactMilestone}
                                                        onValueChange={
                                                            setImpactMilestone
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label="Impacted milestone"
                                                            data-test="impact-milestone"
                                                        >
                                                            <SelectValue placeholder="Milestone" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">
                                                                No schedule
                                                                impact
                                                            </SelectItem>
                                                            {milestones.map(
                                                                (milestone) => (
                                                                    <SelectItem
                                                                        key={
                                                                            milestone.id
                                                                        }
                                                                        value={
                                                                            milestone.id
                                                                        }
                                                                    >
                                                                        {
                                                                            milestone.title
                                                                        }
                                                                        {milestone.baselineDate !==
                                                                        null
                                                                            ? ` — ${milestone.baselineDate}`
                                                                            : ''}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError
                                                        message={
                                                            errors.impact_milestone_id
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Input
                                                        name="impact_days"
                                                        type="number"
                                                        step="1"
                                                        defaultValue={
                                                            changeRequest.impactDays ??
                                                            ''
                                                        }
                                                        placeholder="± days"
                                                        aria-label="Impact in days"
                                                        disabled={
                                                            impactMilestone ===
                                                            'none'
                                                        }
                                                        className="rounded-none border-[1.5px] border-ink text-right font-plex-mono shadow-none dark:border-paper"
                                                        data-test="impact-days"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.impact_days
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            <p className="text-[12px] text-stone dark:text-fog">
                                                A milestone reference plus a day
                                                count — no free-text dates. On
                                                approval the next baseline
                                                version moves the milestone by
                                                exactly this count.
                                            </p>
                                        </div>

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="scope-added">
                                                    Added scope
                                                </Label>
                                                <textarea
                                                    id="scope-added"
                                                    name="scope_added"
                                                    rows={3}
                                                    defaultValue={
                                                        changeRequest.scopeAdded ??
                                                        ''
                                                    }
                                                    className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                />
                                                <InputError
                                                    message={errors.scope_added}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="scope-removed">
                                                    Removed scope
                                                </Label>
                                                <textarea
                                                    id="scope-removed"
                                                    name="scope_removed"
                                                    rows={3}
                                                    defaultValue={
                                                        changeRequest.scopeRemoved ??
                                                        ''
                                                    }
                                                    className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                />
                                                <InputError
                                                    message={
                                                        errors.scope_removed
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="alternatives">
                                                Trade-off alternatives (swap /
                                                defer)
                                            </Label>
                                            <textarea
                                                id="alternatives"
                                                name="alternatives"
                                                rows={2}
                                                defaultValue={
                                                    changeRequest.alternatives ??
                                                    ''
                                                }
                                                className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                            />
                                            <InputError
                                                message={errors.alternatives}
                                            />
                                        </div>

                                        <InputError
                                            message={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string | undefined
                                                    >
                                                ).assessment
                                            }
                                        />
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                variant="secondary"
                                                className="rounded-none shadow-none"
                                                data-test="save-assessment"
                                            >
                                                Save assessment
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <div className="flex flex-col gap-4 px-4 py-4 text-[13px]">
                                <div>
                                    <span className={sectionLabel}>
                                        Effort as role mix
                                    </span>
                                    {assessment.allocations.length === 0 ? (
                                        <p className="mt-1 text-stone dark:text-fog">
                                            No role mix assessed.
                                        </p>
                                    ) : (
                                        <ul className="mt-1 flex flex-col gap-1">
                                            {assessment.allocations.map(
                                                (allocation) => (
                                                    <li
                                                        key={allocation.id}
                                                        className="flex justify-between font-plex-mono"
                                                    >
                                                        <span>
                                                            {
                                                                allocation.roleName
                                                            }{' '}
                                                            × {allocation.days}d
                                                        </span>
                                                        <span>
                                                            {allocation.cost
                                                                ?.formatted ??
                                                                ''}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                                <div>
                                    <span className={sectionLabel}>
                                        Affected items
                                    </span>
                                    <p className="mt-1">
                                        {assessment.affectedItemIds.length === 0
                                            ? '—'
                                            : baselineItems
                                                  .filter((item) =>
                                                      assessment.affectedItemIds.includes(
                                                          item.id,
                                                      ),
                                                  )
                                                  .map((item) => item.title)
                                                  .join(', ')}
                                    </p>
                                </div>
                                <div>
                                    <span className={sectionLabel}>
                                        Schedule impact
                                    </span>
                                    <p className="mt-1">
                                        {changeRequest.impactMilestoneId ===
                                        null
                                            ? 'None'
                                            : `${
                                                  baselineItems.find(
                                                      (item) =>
                                                          item.id ===
                                                          changeRequest.impactMilestoneId,
                                                  )?.title ?? 'Milestone'
                                              } ${
                                                  (changeRequest.impactDays ??
                                                      0) >= 0
                                                      ? '+'
                                                      : ''
                                              }${changeRequest.impactDays ?? 0} days`}
                                    </p>
                                </div>
                                {(changeRequest.scopeAdded !== null ||
                                    changeRequest.scopeRemoved !== null ||
                                    changeRequest.alternatives !== null) && (
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <span className={sectionLabel}>
                                                Added
                                            </span>
                                            <p className="mt-1">
                                                {changeRequest.scopeAdded ??
                                                    '—'}
                                            </p>
                                        </div>
                                        <div>
                                            <span className={sectionLabel}>
                                                Removed
                                            </span>
                                            <p className="mt-1">
                                                {changeRequest.scopeRemoved ??
                                                    '—'}
                                            </p>
                                        </div>
                                        <div>
                                            <span className={sectionLabel}>
                                                Alternatives
                                            </span>
                                            <p className="mt-1">
                                                {changeRequest.alternatives ??
                                                    '—'}
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {changeRequest.status !== 'draft' && can.viewCommercials && (
                    <div className={cardClasses} data-test="commercials-card">
                        <div className={cardHeaderClasses}>
                            <span className={sectionLabel}>
                                Commercial terms — internal only
                            </span>
                            <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                Cost and margin never reach the portal
                            </span>
                        </div>
                        <div className="grid gap-0 sm:grid-cols-4">
                            <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                                <div className={sectionLabel}>Derived cost</div>
                                <div className="mt-1 font-plex-mono text-[18px] font-bold">
                                    {assessmentEditable
                                        ? formatCents(liveCost)
                                        : (assessment.cost?.formatted ?? '—')}
                                </div>
                            </div>
                            <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                                <div className={sectionLabel}>
                                    Suggested price
                                </div>
                                <div className="mt-1 font-plex-mono text-[18px] font-bold">
                                    {assessmentEditable
                                        ? formatCents(liveSuggested)
                                        : (assessment.suggestedPrice
                                              ?.formatted ?? '—')}
                                </div>
                                <div className="text-[11px] text-stone dark:text-fog">
                                    cost × target margin from the pinned sell
                                    rates
                                </div>
                            </div>
                            <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                                <div className={sectionLabel}>
                                    Customer price
                                </div>
                                {changeRequest.status === 'customer_proposal' &&
                                can.update ? (
                                    <Form
                                        {...ChangeRequestProposalController.update.form(
                                            changeRequest.id,
                                        )}
                                        options={{
                                            preserveScroll: true,
                                            preserveState: true,
                                        }}
                                        className="mt-1 flex flex-col gap-1"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="flex gap-2">
                                                    <Input
                                                        name="customer_price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        required
                                                        value={priceInput}
                                                        onChange={(event) =>
                                                            setPriceInput(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder={
                                                            liveSuggested > 0
                                                                ? String(
                                                                      liveSuggested /
                                                                          100,
                                                                  )
                                                                : '0,00'
                                                        }
                                                        aria-label="Customer price in euros"
                                                        className="rounded-none border-[1.5px] border-ink text-right font-plex-mono shadow-none dark:border-paper"
                                                        data-test="customer-price"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        variant="secondary"
                                                        size="sm"
                                                        className="rounded-none shadow-none"
                                                        data-test="save-price"
                                                    >
                                                        Set
                                                    </Button>
                                                </div>
                                                {priceInput === '' &&
                                                    liveSuggested > 0 && (
                                                        <button
                                                            type="button"
                                                            className="text-left text-[11px] text-stone underline dark:text-fog"
                                                            onClick={() =>
                                                                setPriceInput(
                                                                    String(
                                                                        liveSuggested /
                                                                            100,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Use suggestion{' '}
                                                            {formatCents(
                                                                liveSuggested,
                                                            )}
                                                        </button>
                                                    )}
                                                <InputError
                                                    message={
                                                        errors.customer_price
                                                    }
                                                />
                                            </>
                                        )}
                                    </Form>
                                ) : (
                                    <div className="mt-1 font-plex-mono text-[18px] font-bold">
                                        {changeRequest.customerPrice
                                            ?.formatted ?? '—'}
                                    </div>
                                )}
                            </div>
                            <div className="px-4 py-3">
                                <div className={sectionLabel}>
                                    Derived margin
                                </div>
                                <div
                                    className={cn(
                                        'mt-1 font-plex-mono text-[18px] font-bold',
                                        (assessmentEditable
                                            ? (liveMargin ?? 0) < 0
                                            : (assessment.margin?.amount ?? 0) <
                                              0) && 'text-rust',
                                    )}
                                >
                                    {assessmentEditable &&
                                    priceInput !== '' &&
                                    liveMargin !== null
                                        ? `${formatCents(liveMargin)}${
                                              priceCents > 0
                                                  ? ` · ${Math.round((liveMargin / priceCents) * 100)}%`
                                                  : ''
                                          }`
                                        : assessment.margin !== null
                                          ? `${assessment.margin.formatted}${
                                                assessment.marginPercent !==
                                                null
                                                    ? ` · ${assessment.marginPercent}%`
                                                    : ''
                                            }`
                                          : '—'}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {(can.moveToProposal || can.submit) && (
                    <div className="flex flex-wrap items-center gap-3">
                        {can.moveToProposal && (
                            <Form
                                {...ChangeRequestController.transition.form(
                                    changeRequest.id,
                                )}
                            >
                                {({ processing, errors }) => (
                                    <div className="flex flex-col gap-1">
                                        <input
                                            type="hidden"
                                            name="status"
                                            value="customer_proposal"
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                            data-test="move-to-proposal"
                                        >
                                            Move to customer proposal →
                                        </Button>
                                        <InputError message={errors.status} />
                                    </div>
                                )}
                            </Form>
                        )}
                        {changeRequest.status === 'customer_proposal' &&
                            can.startAssessment && (
                                <Form
                                    {...ChangeRequestController.transition.form(
                                        changeRequest.id,
                                    )}
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="status"
                                                value="under_assessment"
                                            />
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                variant="outline"
                                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                                data-test="back-to-assessment"
                                            >
                                                ← Back to assessment
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            )}
                        {can.submit && (
                            <Dialog
                                open={submitting}
                                onOpenChange={setSubmitting}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                        data-test="submit-to-customer"
                                    >
                                        Submit to customer →
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Submit for customer approval
                                    </DialogTitle>
                                    <DialogDescription>
                                        The proposal freezes as an immutable
                                        snapshot — price, scope and schedule
                                        only. Approvers get a personal signed
                                        link and reminders until the respond-by
                                        deadline.
                                    </DialogDescription>
                                    <Form
                                        {...ChangeRequestSubmissionController.store.form(
                                            changeRequest.id,
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
                                                            errors.customer_price
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.allocations
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.approvers
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
                                                        data-test="confirm-submit"
                                                    >
                                                        Freeze & submit →
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                )}

                <div className={cardClasses} data-test="responses-card">
                    <div className={cardHeaderClasses}>
                        <span className={sectionLabel}>
                            Customer decisions on record
                        </span>
                    </div>
                    {responses.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            No responses yet. Every approval, rejection and
                            clarification request is stored immutably against
                            the frozen snapshot it was made on.
                        </p>
                    ) : (
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
                                                    'approved' &&
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
                    )}
                </div>
            </div>
        </>
    );
}
