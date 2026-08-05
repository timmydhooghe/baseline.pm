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
import type { EngagementStatus, SelectOption } from '@/types';

type EngagementDetail = {
    id: string;
    name: string;
    status: EngagementStatus;
    statusLabel: string;
    customer: { id: string; name: string };
    createdAt: string | null;
    allowedTransitions: SelectOption[];
};

type Props = {
    engagement: EngagementDetail;
    lifecycle: SelectOption[];
    can: { transition: boolean; viewCustomer: boolean };
};

export default function EngagementsShow({ engagement, lifecycle, can }: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
        ],
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

                {can.transition && engagement.allowedTransitions.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3">
                        {engagement.allowedTransitions.map((target) =>
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
                                            searchable and free up a plan slot.
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
