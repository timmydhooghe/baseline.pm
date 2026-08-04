import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import EngagementController from '@/actions/App/Http/Controllers/EngagementController';
import EngagementStatusBadge from '@/components/engagement-status-badge';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
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
import { index as customersIndex } from '@/routes/customers';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import type { EngagementStatus } from '@/types';

type EngagementListItem = {
    id: string;
    name: string;
    status: EngagementStatus;
    statusLabel: string;
    customerName: string;
};

type CustomerOption = {
    id: string;
    name: string;
};

type Props = {
    engagements: EngagementListItem[];
    plan: { label: string; activeCount: number; limit: number | null };
    customers: CustomerOption[];
    filters: { q: string };
    can: { create: boolean };
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export default function EngagementsIndex({
    engagements: engagementList,
    plan,
    customers,
    filters,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.q);
    const limitReached = plan.limit !== null && plan.activeCount >= plan.limit;

    return (
        <>
            <Head title="Engagements" />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Engagements
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            All engagements
                        </h1>
                    </div>

                    {can.create && (
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                    data-test="new-engagement-button"
                                >
                                    New engagement
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>New engagement</DialogTitle>
                                <DialogDescription>
                                    Starts as a draft — the baseline comes
                                    later.{' '}
                                    {plan.limit !== null &&
                                        `Your ${plan.label} plan allows ${plan.limit} active engagement${plan.limit === 1 ? '' : 's'}; archived ones don't count.`}
                                </DialogDescription>

                                {customers.length === 0 ? (
                                    <p className="text-[14px] text-stone dark:text-fog">
                                        An engagement belongs to a customer.{' '}
                                        <TextLink href={customersIndex()}>
                                            Create a customer record
                                        </TextLink>{' '}
                                        first.
                                    </p>
                                ) : (
                                    <Form
                                        {...EngagementController.store.form()}
                                        resetOnSuccess
                                        className="space-y-6"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="engagement-name">
                                                        Name
                                                    </Label>
                                                    <Input
                                                        id="engagement-name"
                                                        name="name"
                                                        required
                                                        autoFocus
                                                        placeholder="ERP rollout"
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="engagement-customer">
                                                        Customer
                                                    </Label>
                                                    <Select name="customer_id">
                                                        <SelectTrigger id="engagement-customer">
                                                            <SelectValue placeholder="Pick a customer" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {customers.map(
                                                                (customer) => (
                                                                    <SelectItem
                                                                        key={
                                                                            customer.id
                                                                        }
                                                                        value={
                                                                            customer.id
                                                                        }
                                                                    >
                                                                        {
                                                                            customer.name
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError
                                                        message={
                                                            errors.customer_id
                                                        }
                                                    />
                                                </div>
                                                <InputError
                                                    message={errors.plan}
                                                />
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button variant="secondary">
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        data-test="create-engagement-button"
                                                    >
                                                        Create draft
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-[1.5px] border-ink px-4 py-3 dark:border-paper">
                    <span
                        className={cn(
                            'font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase',
                            limitReached
                                ? 'text-rust'
                                : 'text-stone dark:text-fog',
                        )}
                    >
                        Plan · {plan.label} —{' '}
                        {plan.limit === null
                            ? `${plan.activeCount} active, no limit`
                            : `${plan.activeCount} of ${plan.limit} active`}
                    </span>
                    <form
                        className="flex items-center gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                engagements().url,
                                search === '' ? {} : { q: search },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search engagements or customers"
                            aria-label="Search engagements"
                            className="h-8 w-64 rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            className="rounded-none border-[1.5px] border-ink font-plex-mono text-[11px] font-semibold uppercase shadow-none dark:border-paper"
                        >
                            Search
                        </Button>
                    </form>
                </div>

                {engagementList.length === 0 ? (
                    <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                        <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                            {filters.q === ''
                                ? 'No engagements yet'
                                : 'Nothing matches your search'}
                        </div>
                        <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                            {filters.q === ''
                                ? 'Start an engagement for a customer — it begins as a draft and moves through baseline, delivery and acceptance.'
                                : 'Archived engagements stay searchable, so try a different term.'}
                        </p>
                    </div>
                ) : (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <table className="w-full text-left text-[14px]">
                            <thead>
                                <tr className="border-b-[1.5px] border-ink dark:border-paper">
                                    <th className={tableHeading}>Name</th>
                                    <th className={tableHeading}>Customer</th>
                                    <th className={tableHeading}>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {engagementList.map(
                                    (engagement, engagementIndex) => (
                                        <tr
                                            key={engagement.id}
                                            className={cn(
                                                engagementIndex <
                                                    engagementList.length - 1 &&
                                                    'border-b border-ink/20 dark:border-paper/20',
                                            )}
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                <Link
                                                    href={engagementShow(
                                                        engagement.id,
                                                    )}
                                                    prefetch
                                                    className="hover:text-rust"
                                                >
                                                    {engagement.name}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 text-stone dark:text-fog">
                                                {engagement.customerName}
                                            </td>
                                            <td className="px-4 py-3">
                                                <EngagementStatusBadge
                                                    status={engagement.status}
                                                    label={
                                                        engagement.statusLabel
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ),
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

EngagementsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Engagements',
            href: engagements(),
        },
    ],
};
