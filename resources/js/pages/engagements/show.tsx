import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/react';
import { useState } from 'react';
import EngagementController from '@/actions/App/Http/Controllers/EngagementController';
import FinalAcceptanceController from '@/actions/App/Http/Controllers/FinalAcceptanceController';
import EngagementStatusBadge from '@/components/engagement-status-badge';
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
import { show as customerShow } from '@/routes/customers';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as auditShow } from '@/routes/engagements/audit';
import { show as baselineShow } from '@/routes/engagements/baseline';
import { index as changeRequestsIndex } from '@/routes/engagements/change-requests';
import { index as decisionsIndex } from '@/routes/engagements/decisions';
import { index as deliverablesIndex } from '@/routes/engagements/deliverables';
import { index as dependenciesIndex } from '@/routes/engagements/dependencies';
import { index as risksIndex } from '@/routes/engagements/risks';
import { show as workShow } from '@/routes/engagements/work';
import type {
    BaselineStatus,
    EngagementAcceptanceSummary,
    EngagementPositionSummary,
    EngagementStatus,
    EngagementWorkSummary,
    SelectOption,
} from '@/types';

type EngagementDetail = {
    id: string;
    name: string;
    status: EngagementStatus;
    statusLabel: string;
    customer: { id: string; name: string };
    createdAt: string | null;
    allowedTransitions: SelectOption[];
};

type BaselineSummary = {
    id: string;
    version: number;
    status: BaselineStatus;
    statusLabel: string;
};

type ChangeControlSummary = {
    total: number;
    open: number;
    awaiting: number;
};

type GovernanceSummary = {
    decisions: {
        total: number;
        drafts: number;
        awaitingAcknowledgement: number;
    };
    risks: { live: number; escalated: number };
    dependencies: {
        outstanding: number;
        late: number;
        customerOwed: number;
    };
    auditEntries: number;
};

type Props = {
    engagement: EngagementDetail;
    baseline: BaselineSummary | null;
    work: EngagementWorkSummary;
    changeControl: ChangeControlSummary;
    acceptance: EngagementAcceptanceSummary;
    governance: GovernanceSummary;
    lifecycle: SelectOption[];
    position: EngagementPositionSummary;
    can: { transition: boolean; viewCustomer: boolean };
};

export default function EngagementsShow({
    engagement,
    baseline,
    work,
    changeControl,
    acceptance,
    governance,
    lifecycle,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
        ],
        position,
    });

    const { errors } = usePage().props;
    const [requestingAcceptance, setRequestingAcceptance] = useState(false);

    const currentIndex = lifecycle.findIndex(
        (status) => status.value === engagement.status,
    );
    const isArchived = engagement.status === 'archived';

    const allSignedOff =
        acceptance.total > 0 && acceptance.accepted === acceptance.total;
    const canRequestFinalAcceptance =
        can.transition && engagement.status === 'active' && allSignedOff;

    const transition = (status: string) =>
        router.post(
            EngagementController.transition.url(engagement.id),
            { status },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={engagement.name} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Engagement
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 text-[14px] text-stone dark:text-fog">
                            for{' '}
                            {can.viewCustomer ? (
                                <Link
                                    href={customerShow(engagement.customer.id)}
                                    prefetch
                                    className="font-medium text-ink hover:text-rust dark:text-paper dark:hover:text-rust"
                                >
                                    {engagement.customer.name}
                                </Link>
                            ) : (
                                <span className="font-medium text-ink dark:text-paper">
                                    {engagement.customer.name}
                                </span>
                            )}
                            {engagement.createdAt !== null &&
                                ` · started ${engagement.createdAt}`}
                        </p>
                    </div>
                    <EngagementStatusBadge
                        status={engagement.status}
                        label={engagement.statusLabel}
                        className="text-[12px]"
                    />
                </div>

                {isArchived && (
                    <div className="border-[1.5px] border-ink/40 px-4 py-3 dark:border-paper/40">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Archived — read-only. It stays searchable and no
                            longer counts toward your plan limit.
                        </span>
                    </div>
                )}

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Lifecycle
                        </span>
                    </div>
                    <ol className="flex flex-col gap-0 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:gap-y-3">
                        {lifecycle.map((status, statusIndex) => {
                            const isCurrent = statusIndex === currentIndex;
                            const isPast = statusIndex < currentIndex;

                            return (
                                <li
                                    key={status.value}
                                    className="flex items-center"
                                >
                                    <span
                                        className={cn(
                                            'flex items-center gap-2 border-[1.5px] px-2.5 py-1 font-plex-mono text-[11px] font-semibold whitespace-nowrap uppercase',
                                            isCurrent &&
                                                'border-rust bg-rust text-paper',
                                            isPast &&
                                                'border-ink bg-ink text-paper dark:border-paper dark:bg-paper dark:text-ink',
                                            !isCurrent &&
                                                !isPast &&
                                                'border-ink/30 text-stone dark:border-paper/30 dark:text-fog',
                                        )}
                                    >
                                        {statusIndex + 1}. {status.label}
                                    </span>
                                    {statusIndex < lifecycle.length - 1 && (
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'mx-1 hidden h-[1.5px] w-4 sm:block',
                                                statusIndex < currentIndex
                                                    ? 'bg-ink dark:bg-paper'
                                                    : 'bg-ink/30 dark:bg-paper/30',
                                            )}
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Baseline
                        </span>
                        {baseline !== null && (
                            <span className="font-plex-mono text-[11px] font-semibold uppercase">
                                v{baseline.version} · {baseline.statusLabel}
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <p className="max-w-xl text-[13px] text-stone dark:text-fog">
                            {baseline === null &&
                                'Turn the signed contract into the commitment ledger: structured scope, derived cost budget, immutable approval snapshot.'}
                            {baseline?.status === 'draft' &&
                                'A draft is being prepared in the six-step builder.'}
                            {baseline?.status === 'awaiting_approval' &&
                                'Submitted — the frozen snapshot awaits the customer approver.'}
                            {baseline?.status === 'approved' &&
                                'Approved and immutable. Changes go through change requests, each creating the next version.'}
                        </p>
                        <Button
                            asChild
                            variant="outline"
                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                            data-test="open-baseline-builder-button"
                        >
                            <Link href={baselineShow(engagement.id)} prefetch>
                                {baseline === null && can.transition
                                    ? 'Build baseline →'
                                    : baseline?.status === 'draft' &&
                                        can.transition
                                      ? 'Continue in builder →'
                                      : 'View baseline →'}
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Execution work
                        </span>
                        <span className="font-plex-mono text-[11px] font-semibold uppercase">
                            {work.connections.length === 0
                                ? 'Standalone'
                                : work.connections
                                      .map(
                                          (connection) =>
                                              `${connection.providerLabel} · ${
                                                  connection.status ===
                                                  'connected'
                                                      ? connection.lastSyncedAt ===
                                                        null
                                                          ? 'sync queued'
                                                          : `synced ${connection.lastSyncedAt}`
                                                      : 'disconnected'
                                              }`,
                                      )
                                      .join(' / ')}
                        </span>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <p className="max-w-xl text-[13px] text-stone dark:text-fog">
                            {work.itemCount === 0 &&
                                'Sync Jira or Linear — or record work manually — and map every item to a deliverable. Unmapped work is potential scope creep.'}
                            {work.itemCount > 0 &&
                                work.unlinkedCount === 0 &&
                                `${work.itemCount} work ${work.itemCount === 1 ? 'item' : 'items'}, all mapped to deliverables.`}
                            {work.itemCount > 0 && work.unlinkedCount > 0 && (
                                <>
                                    {work.itemCount} work{' '}
                                    {work.itemCount === 1 ? 'item' : 'items'},{' '}
                                    <span className="font-semibold text-rust">
                                        {work.unlinkedCount} unmapped
                                    </span>{' '}
                                    — potential scope creep.
                                </>
                            )}
                        </p>
                        <Button
                            asChild
                            variant="outline"
                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                            data-test="open-work-button"
                        >
                            <Link href={workShow(engagement.id)} prefetch>
                                Open work →
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Change control
                        </span>
                        {changeControl.total > 0 && (
                            <span className="font-plex-mono text-[11px] font-semibold uppercase">
                                {changeControl.open} open
                                {changeControl.awaiting > 0 &&
                                    ` · ${changeControl.awaiting} awaiting decision`}
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <p className="max-w-xl text-[13px] text-stone dark:text-fog">
                            {changeControl.total === 0 &&
                                'Every change to the approved baseline travels through a structured request: assessed effort, a priced proposal, an immutable customer decision.'}
                            {changeControl.total > 0 &&
                                changeControl.awaiting === 0 &&
                                `${changeControl.total} change ${changeControl.total === 1 ? 'request' : 'requests'} on record.`}
                            {changeControl.awaiting > 0 && (
                                <>
                                    {changeControl.total} change{' '}
                                    {changeControl.total === 1
                                        ? 'request'
                                        : 'requests'}
                                    ,{' '}
                                    <span className="font-semibold text-rust">
                                        {changeControl.awaiting} frozen for
                                        customer decision
                                    </span>
                                    .
                                </>
                            )}
                        </p>
                        <Button
                            asChild
                            variant="outline"
                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                            data-test="open-change-requests-button"
                        >
                            <Link
                                href={changeRequestsIndex(engagement.id)}
                                prefetch
                            >
                                Open change control →
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Deliverables &amp; acceptance
                        </span>
                        {acceptance.total > 0 && (
                            <span className="font-plex-mono text-[11px] font-semibold uppercase">
                                {acceptance.accepted}/{acceptance.total} signed
                                {acceptance.awaiting > 0 &&
                                    ` · ${acceptance.awaiting} awaiting signature`}
                                {' · '}
                                {acceptance.acceptedValue.formatted} accepted
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <p className="max-w-xl text-[13px] text-stone dark:text-fog">
                            {acceptance.total === 0 &&
                                'Deliverable records appear once a baseline is approved. Each carries progress, evidence and its own acceptance review.'}
                            {acceptance.total > 0 && !allSignedOff && (
                                <>
                                    {acceptance.total} deliverable
                                    {acceptance.total === 1 ? '' : 's'} on
                                    record.{' '}
                                    <span className="font-semibold">
                                        Accepted always means signed
                                    </span>{' '}
                                    — the customer reviews a frozen snapshot and
                                    the signed value accrues to your position.
                                </>
                            )}
                            {allSignedOff &&
                                'Every deliverable is signed off. The engagement can go to the customer for final acceptance.'}
                        </p>
                        <Button
                            asChild
                            variant="outline"
                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                            data-test="open-deliverables-button"
                        >
                            <Link
                                href={deliverablesIndex(engagement.id)}
                                prefetch
                            >
                                Open deliverables →
                            </Link>
                        </Button>
                    </div>

                    {acceptance.finalAcceptance !== null && (
                        <div
                            className={cn(
                                'border-t-[1.5px] px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase',
                                acceptance.finalAcceptance.status ===
                                    'accepted' && 'border-moss text-moss',
                                acceptance.finalAcceptance.status ===
                                    'awaiting_response' &&
                                    'border-ink bg-sun/40 dark:border-paper',
                                (acceptance.finalAcceptance.status ===
                                    'rejected' ||
                                    acceptance.finalAcceptance.status ===
                                        'clarification_requested') &&
                                    'border-rust text-rust',
                                acceptance.finalAcceptance.status ===
                                    'withdrawn' &&
                                    'border-ink/40 text-stone dark:border-paper/40 dark:text-fog',
                            )}
                            data-test="final-acceptance-state"
                        >
                            {acceptance.finalAcceptance.status ===
                                'awaiting_response' &&
                                `Final acceptance with the customer — response due ${acceptance.finalAcceptance.respondBy}.`}
                            {acceptance.finalAcceptance.status === 'accepted' &&
                                `Final acceptance signed by ${acceptance.finalAcceptance.decidedBy} on ${acceptance.finalAcceptance.decidedAt}.`}
                            {(acceptance.finalAcceptance.status ===
                                'rejected' ||
                                acceptance.finalAcceptance.status ===
                                    'clarification_requested') &&
                                `${acceptance.finalAcceptance.statusLabel} on ${acceptance.finalAcceptance.decidedAt} by ${acceptance.finalAcceptance.decidedBy} — back with the delivery team.`}
                            {acceptance.finalAcceptance.status ===
                                'withdrawn' &&
                                'The last final acceptance request was withdrawn.'}
                        </div>
                    )}
                </div>

                <div
                    className="border-[1.5px] border-ink dark:border-paper"
                    data-test="governance-card"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Governance ledgers
                        </span>
                        <span className="font-plex-mono text-[11px] font-semibold uppercase">
                            {governance.auditEntries} audited actions
                        </span>
                    </div>
                    <div className="grid divide-ink/15 sm:grid-cols-3 sm:divide-x dark:divide-paper/15">
                        {[
                            {
                                title: 'Decisions',
                                href: decisionsIndex(engagement.id),
                                lead: `${governance.decisions.total} on record`,
                                note:
                                    governance.decisions.drafts > 0
                                        ? `${governance.decisions.drafts} draft${governance.decisions.drafts === 1 ? '' : 's'} awaiting confirmation`
                                        : 'Why the engagement is the way it is.',
                                warn: governance.decisions.drafts > 0,
                                test: 'open-decisions',
                            },
                            {
                                title: 'Risks',
                                href: risksIndex(engagement.id),
                                lead: `${governance.risks.live} live`,
                                note:
                                    governance.risks.escalated > 0
                                        ? `${governance.risks.escalated} escalated or worsening`
                                        : 'Rated, owned, and priced from the rate card.',
                                warn: governance.risks.escalated > 0,
                                test: 'open-risks',
                            },
                            {
                                title: 'Dependencies',
                                href: dependenciesIndex(engagement.id),
                                lead: `${governance.dependencies.outstanding} outstanding`,
                                note:
                                    governance.dependencies.late > 0
                                        ? `${governance.dependencies.late} late · ${governance.dependencies.customerOwed} owed by the customer`
                                        : `${governance.dependencies.customerOwed} owed by the customer`,
                                warn: governance.dependencies.late > 0,
                                test: 'open-dependencies',
                            },
                        ].map((ledger) => (
                            <Link
                                key={ledger.title}
                                href={ledger.href}
                                prefetch
                                data-test={ledger.test}
                                className="flex flex-col gap-1 px-4 py-3 transition-colors hover:bg-ink/5 dark:hover:bg-paper/5"
                            >
                                <span className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                    {ledger.title}
                                </span>
                                <span className="font-plex-mono text-[18px] font-semibold">
                                    {ledger.lead}
                                </span>
                                <span
                                    className={cn(
                                        'text-[12px]',
                                        ledger.warn
                                            ? 'font-semibold text-rust'
                                            : 'text-stone dark:text-fog',
                                    )}
                                >
                                    {ledger.note}
                                </span>
                            </Link>
                        ))}
                    </div>
                    <div className="border-t-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <Link
                            href={auditShow(engagement.id)}
                            prefetch
                            className="font-plex-mono text-[12px] font-semibold uppercase underline hover:text-rust"
                            data-test="open-audit-trail"
                        >
                            Open the audit trail →
                        </Link>
                    </div>
                </div>

                {canRequestFinalAcceptance && (
                    <div className="flex flex-wrap items-center gap-3">
                        <Dialog
                            open={requestingAcceptance}
                            onOpenChange={setRequestingAcceptance}
                        >
                            <DialogTrigger asChild>
                                <Button
                                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                    data-test="open-final-acceptance"
                                >
                                    Submit for final acceptance →
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Submit {engagement.name} for final
                                    acceptance
                                </DialogTitle>
                                <DialogDescription>
                                    The signed record freezes into an immutable
                                    snapshot and goes to the customer's
                                    approvers. Their signature — and only their
                                    signature — completes the engagement.
                                </DialogDescription>
                                <Form
                                    {...FinalAcceptanceController.store.form(
                                        engagement.id,
                                    )}
                                    onSuccess={() =>
                                        setRequestingAcceptance(false)
                                    }
                                    className="flex flex-col gap-4"
                                >
                                    {({ processing, errors: formErrors }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="final-respond-by">
                                                    Respond by
                                                </Label>
                                                <Input
                                                    id="final-respond-by"
                                                    name="respond_by"
                                                    type="date"
                                                    required
                                                    className="rounded-none border-[1.5px] border-ink font-plex-mono shadow-none dark:border-paper"
                                                    data-test="final-respond-by"
                                                />
                                                <InputError
                                                    message={
                                                        formErrors.respond_by
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
                                                    data-test="submit-final-acceptance"
                                                >
                                                    Freeze &amp; submit →
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                )}

                {can.transition && engagement.allowedTransitions.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3">
                        {engagement.allowedTransitions
                            .filter(
                                (target) =>
                                    /*
                                     * Approval gates own their roads: a
                                     * baseline submission opens the review,
                                     * a final acceptance submission opens the
                                     * acceptance gate, and only the customer's
                                     * signature completes the engagement.
                                     */
                                    target.value !==
                                        'awaiting_baseline_approval' &&
                                    target.value !==
                                        'awaiting_final_acceptance' &&
                                    target.value !== 'completed',
                            )
                            .map((target) =>
                                target.value === 'archived' ? (
                                    <Dialog key={target.value}>
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="outline"
                                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                                data-test="archive-engagement-button"
                                            >
                                                Archive engagement
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogTitle>
                                                Archive {engagement.name}?
                                            </DialogTitle>
                                            <DialogDescription>
                                                Archived engagements become
                                                read-only forever. They stay
                                                searchable and free up a plan
                                                slot.
                                            </DialogDescription>
                                            <DialogFooter className="gap-2">
                                                <DialogClose asChild>
                                                    <Button variant="secondary">
                                                        Cancel
                                                    </Button>
                                                </DialogClose>
                                                <Button
                                                    data-test="confirm-archive-engagement-button"
                                                    onClick={() =>
                                                        transition(target.value)
                                                    }
                                                >
                                                    Archive
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                ) : (
                                    <Button
                                        key={target.value}
                                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                        data-test={`transition-${target.value}-button`}
                                        onClick={() => transition(target.value)}
                                    >
                                        Move to {target.label} →
                                    </Button>
                                ),
                            )}
                        <InputError message={errors.status} />
                    </div>
                )}
            </div>
        </>
    );
}
