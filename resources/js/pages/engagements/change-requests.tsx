import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import ChangeRequestController from '@/actions/App/Http/Controllers/ChangeRequestController';
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
import { show as changeRequestShow } from '@/routes/change-requests';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as changeRequestsIndex } from '@/routes/engagements/change-requests';
import type {
    ChangeRequestListItem,
    EngagementPositionSummary,
    EngagementStatus,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    changeRequests: ChangeRequestListItem[];
    position: EngagementPositionSummary;
    can: { create: boolean };
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const originOptions = [
    { value: 'steering_call', label: 'Steering call' },
    { value: 'email', label: 'Email' },
    { value: 'meeting', label: 'Meeting' },
    { value: 'other', label: 'Other' },
];

export default function EngagementChangeRequests({
    engagement,
    changeRequests,
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
        ],
        position,
    });

    const [raising, setRaising] = useState(false);

    const open = changeRequests.filter(
        (changeRequest) =>
            changeRequest.status !== 'approved' &&
            changeRequest.status !== 'rejected',
    ).length;
    const awaiting = changeRequests.filter(
        (changeRequest) => changeRequest.status === 'awaiting_approval',
    ).length;
    const breaches = changeRequests.filter(
        (changeRequest) =>
            changeRequest.breachRisk &&
            changeRequest.status !== 'approved' &&
            changeRequest.status !== 'rejected',
    ).length;

    const stats = [
        { label: 'Open', value: String(open), warn: open > 0 },
        {
            label: 'Awaiting decision',
            value: String(awaiting),
            warn: awaiting > 0,
        },
        { label: 'Breach risks', value: String(breaches), warn: breaches > 0 },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Change requests`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Change control
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Every change to the committed baseline travels
                            through here: structured assessment, a priced
                            customer proposal, an immutable decision — and on
                            approval, the next baseline version.
                        </p>
                    </div>
                    <div
                        className="flex gap-3"
                        data-test="change-requests-summary"
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
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Change requests
                        </span>
                        {can.create && (
                            <Dialog open={raising} onOpenChange={setRaising}>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="raise-change-request"
                                    >
                                        Raise change request
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-xl">
                                    <DialogTitle>
                                        Raise a change request
                                    </DialogTitle>
                                    <DialogDescription>
                                        Drift items draft their own change
                                        requests from triage — this is for
                                        changes surfacing through other
                                        channels.
                                    </DialogDescription>
                                    <Form
                                        {...ChangeRequestController.store.form(
                                            engagement.id,
                                        )}
                                        onSuccess={() => setRaising(false)}
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="cr-title">
                                                        Title
                                                    </Label>
                                                    <Input
                                                        id="cr-title"
                                                        name="title"
                                                        required
                                                        placeholder="e.g. Supplier portal module"
                                                        className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                                        data-test="cr-title"
                                                    />
                                                    <InputError
                                                        message={errors.title}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="cr-what">
                                                        What changes?
                                                    </Label>
                                                    <textarea
                                                        id="cr-what"
                                                        name="what"
                                                        required
                                                        rows={3}
                                                        placeholder="What is being asked for, in the customer's words."
                                                        className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                        data-test="cr-what"
                                                    />
                                                    <InputError
                                                        message={errors.what}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="cr-why">
                                                        Why now? (optional)
                                                    </Label>
                                                    <textarea
                                                        id="cr-why"
                                                        name="why"
                                                        rows={2}
                                                        className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] shadow-none outline-none dark:border-paper"
                                                    />
                                                    <InputError
                                                        message={errors.why}
                                                    />
                                                </div>
                                                <div className="grid gap-4 sm:grid-cols-2">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="cr-origin">
                                                            Origin
                                                        </Label>
                                                        <Select
                                                            name="origin"
                                                            defaultValue="steering_call"
                                                        >
                                                            <SelectTrigger
                                                                id="cr-origin"
                                                                data-test="cr-origin"
                                                            >
                                                                <SelectValue placeholder="Where did it surface?" />
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
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="cr-estimated-days">
                                                            Rough effort, days
                                                            (optional)
                                                        </Label>
                                                        <Input
                                                            id="cr-estimated-days"
                                                            name="estimated_days"
                                                            type="number"
                                                            step="0.25"
                                                            min="0.25"
                                                            className="rounded-none border-[1.5px] border-ink text-right font-plex-mono shadow-none dark:border-paper"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.estimated_days
                                                            }
                                                        />
                                                    </div>
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
                                                        data-test="submit-change-request"
                                                    >
                                                        Draft change request →
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                    {changeRequests.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            No change requests yet. Drift triage drafts them
                            from unmapped work; anything else is raised here.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>
                                            Change request
                                        </th>
                                        <th className={tableHeading}>Status</th>
                                        <th className={tableHeading}>Price</th>
                                        <th className={tableHeading}>
                                            Respond by
                                        </th>
                                        <th className={tableHeading}>
                                            Baseline
                                        </th>
                                        <th className={tableHeading}>Raised</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {changeRequests.map((changeRequest) => (
                                        <tr
                                            key={changeRequest.id}
                                            data-test={`change-request-row-${changeRequest.id}`}
                                        >
                                            <td className="px-4 py-2">
                                                <div className="flex flex-col gap-1">
                                                    <Link
                                                        href={changeRequestShow(
                                                            changeRequest.id,
                                                        )}
                                                        prefetch
                                                        className="font-medium hover:text-rust"
                                                    >
                                                        {changeRequest.title}
                                                    </Link>
                                                    <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                        {changeRequest.originLabel ??
                                                            '—'}
                                                        {changeRequest.workItemKey !==
                                                        null
                                                            ? ` · ${changeRequest.workItemKey}`
                                                            : ''}
                                                        {changeRequest.estimatedDays !==
                                                        null
                                                            ? ` · ~${changeRequest.estimatedDays}d`
                                                            : ''}
                                                    </span>
                                                    {changeRequest.breachRisk && (
                                                        <span className="w-fit border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap text-rust uppercase">
                                                            Started before
                                                            approval
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2">
                                                <ChangeRequestStatusBadge
                                                    status={
                                                        changeRequest.status
                                                    }
                                                    label={
                                                        changeRequest.statusLabel
                                                    }
                                                />
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono font-semibold">
                                                {changeRequest.price
                                                    ?.formatted ?? '—'}
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                {changeRequest.respondBy ===
                                                null ? (
                                                    '—'
                                                ) : (
                                                    <span
                                                        className={
                                                            changeRequest.respondByOverdue
                                                                ? 'font-semibold text-rust'
                                                                : undefined
                                                        }
                                                    >
                                                        {
                                                            changeRequest.respondBy
                                                        }
                                                        {changeRequest.respondByOverdue &&
                                                            ' · overdue'}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                {changeRequest.mintedBaselineVersion !==
                                                null
                                                    ? `→ v${changeRequest.mintedBaselineVersion}`
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2 text-stone dark:text-fog">
                                                {changeRequest.createdAt ?? '—'}
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
