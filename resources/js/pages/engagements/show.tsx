import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import EngagementController from '@/actions/App/Http/Controllers/EngagementController';
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
import { cn } from '@/lib/utils';
import { show as customerShow } from '@/routes/customers';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as baselineShow } from '@/routes/engagements/baseline';
import { show as workShow } from '@/routes/engagements/work';
import type {
    BaselineStatus,
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

type Props = {
    engagement: EngagementDetail;
    baseline: BaselineSummary | null;
    work: EngagementWorkSummary;
    lifecycle: SelectOption[];
    position: EngagementPositionSummary;
    can: { transition: boolean; viewCustomer: boolean };
};

export default function EngagementsShow({
    engagement,
    baseline,
    work,
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

    const currentIndex = lifecycle.findIndex(
        (status) => status.value === engagement.status,
    );
    const isArchived = engagement.status === 'archived';

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

                {can.transition && engagement.allowedTransitions.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3">
                        {engagement.allowedTransitions
                            .filter(
                                (target) =>
                                    target.value !==
                                    'awaiting_baseline_approval',
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
