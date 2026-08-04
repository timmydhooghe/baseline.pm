import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import StakeholderController from '@/actions/App/Http/Controllers/StakeholderController';
import AlertError from '@/components/alert-error';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { index as customers, show as customerShow } from '@/routes/customers';
import { show as engagementShow } from '@/routes/engagements';
import type { EngagementStatus, SelectOption, StakeholderRole } from '@/types';

type CustomerDetail = {
    id: string;
    name: string;
};

type StakeholderListItem = {
    id: string;
    name: string;
    email: string;
    role: StakeholderRole;
    roleLabel: string;
};

type EngagementListItem = {
    id: string;
    name: string;
    status: EngagementStatus;
    statusLabel: string;
};

type Props = {
    customer: CustomerDetail;
    stakeholders: StakeholderListItem[];
    engagements: EngagementListItem[];
    stakeholderRoles: SelectOption[];
    can: { manage: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export default function CustomersShow({
    customer,
    stakeholders,
    engagements,
    stakeholderRoles,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Customers', href: customers() },
            { title: customer.name, href: customerShow(customer.id) },
        ],
    });

    const { errors } = usePage().props;

    return (
        <>
            <Head title={customer.name} />
            <div className="flex flex-col gap-6">
                {errors.customer && (
                    <AlertError
                        title="Customer cannot be deleted"
                        errors={[errors.customer]}
                    />
                )}

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Customer
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {customer.name}
                        </h1>
                    </div>

                    {can.manage && (
                        <div className="flex gap-2">
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                    >
                                        Rename
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>Rename customer</DialogTitle>
                                    <Form
                                        {...CustomerController.update.form(
                                            customer.id,
                                        )}
                                        className="space-y-6"
                                    >
                                        {({
                                            processing,
                                            errors: formErrors,
                                        }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="customer-name">
                                                        Name
                                                    </Label>
                                                    <Input
                                                        id="customer-name"
                                                        name="name"
                                                        required
                                                        defaultValue={
                                                            customer.name
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            formErrors.name
                                                        }
                                                    />
                                                </div>
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button variant="secondary">
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

                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="destructive"
                                        className="rounded-none font-semibold shadow-none"
                                        disabled={engagements.length > 0}
                                        title={
                                            engagements.length > 0
                                                ? 'Customers with engagements cannot be deleted'
                                                : undefined
                                        }
                                        data-test="delete-customer-button"
                                    >
                                        Delete
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Delete {customer.name}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        Its stakeholders lose portal access and
                                        are removed with it. This cannot be
                                        undone.
                                    </DialogDescription>
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            variant="destructive"
                                            data-test="confirm-delete-customer-button"
                                            onClick={() =>
                                                router.delete(
                                                    CustomerController.destroy.url(
                                                        customer.id,
                                                    ),
                                                )
                                            }
                                        >
                                            Delete customer
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </div>
                    )}
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex items-center justify-between border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            Stakeholders · {stakeholders.length}
                        </span>
                        <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                            Client users are always free — no seat math
                        </span>
                    </div>

                    {stakeholders.length > 0 && (
                        <table className="w-full text-left text-[14px]">
                            <thead>
                                <tr className="border-b-[1.5px] border-ink dark:border-paper">
                                    <th className={tableHeading}>Name</th>
                                    <th className={tableHeading}>Email</th>
                                    <th className={tableHeading}>Role</th>
                                    {can.manage && (
                                        <th className={tableHeading}>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {stakeholders.map((stakeholder) => (
                                    <tr
                                        key={stakeholder.id}
                                        className="border-b border-ink/20 dark:border-paper/20"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {stakeholder.name}
                                        </td>
                                        <td className="px-4 py-3 text-stone dark:text-fog">
                                            {stakeholder.email}
                                        </td>
                                        <td className="px-4 py-3">
                                            {can.manage ? (
                                                <Select
                                                    value={stakeholder.role}
                                                    onValueChange={(role) =>
                                                        router.patch(
                                                            StakeholderController.update.url(
                                                                stakeholder.id,
                                                            ),
                                                            {
                                                                name: stakeholder.name,
                                                                email: stakeholder.email,
                                                                role,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        size="sm"
                                                        className="rounded-none border-[1.5px] border-ink font-plex-mono text-[11px] font-semibold uppercase shadow-none dark:border-paper"
                                                        aria-label={`Role of ${stakeholder.name}`}
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {stakeholderRoles.map(
                                                            (role) => (
                                                                <SelectItem
                                                                    key={
                                                                        role.value
                                                                    }
                                                                    value={
                                                                        role.value
                                                                    }
                                                                >
                                                                    {role.label}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <span className="inline-block border-[1.5px] border-ink px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase dark:border-paper">
                                                    {stakeholder.roleLabel}
                                                </span>
                                            )}
                                        </td>
                                        {can.manage && (
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                    onClick={() =>
                                                        router.delete(
                                                            StakeholderController.destroy.url(
                                                                stakeholder.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    {stakeholders.length === 0 && !can.manage && (
                        <p className="px-4 py-6 text-[14px] text-stone dark:text-fog">
                            No stakeholders yet.
                        </p>
                    )}

                    {can.manage && (
                        <Form
                            {...StakeholderController.store.form(customer.id)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="flex flex-wrap items-end gap-3 px-4 py-4"
                        >
                            {({ processing, errors: formErrors }) => (
                                <>
                                    <div className="grid min-w-44 flex-1 gap-1.5">
                                        <Label
                                            htmlFor="stakeholder-name"
                                            className={sectionLabel}
                                        >
                                            Name
                                        </Label>
                                        <Input
                                            id="stakeholder-name"
                                            name="name"
                                            required
                                            placeholder="Petra Molnar"
                                            className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                        />
                                        <InputError message={formErrors.name} />
                                    </div>
                                    <div className="grid min-w-52 flex-1 gap-1.5">
                                        <Label
                                            htmlFor="stakeholder-email"
                                            className={sectionLabel}
                                        >
                                            Email
                                        </Label>
                                        <Input
                                            id="stakeholder-email"
                                            type="email"
                                            name="email"
                                            required
                                            placeholder="pm@acme.eu"
                                            className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                        />
                                        <InputError
                                            message={formErrors.email}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="stakeholder-role"
                                            className={sectionLabel}
                                        >
                                            Role
                                        </Label>
                                        <Select
                                            name="role"
                                            defaultValue="viewer"
                                        >
                                            <SelectTrigger
                                                id="stakeholder-role"
                                                className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {stakeholderRoles.map(
                                                    (role) => (
                                                        <SelectItem
                                                            key={role.value}
                                                            value={role.value}
                                                        >
                                                            {role.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={formErrors.role} />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                        data-test="add-stakeholder-button"
                                    >
                                        Add stakeholder
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            Engagements · {engagements.length}
                        </span>
                    </div>
                    {engagements.length === 0 ? (
                        <p className="px-4 py-6 text-[14px] text-stone dark:text-fog">
                            No engagements for this customer yet.
                        </p>
                    ) : (
                        <table className="w-full text-left text-[14px]">
                            <tbody>
                                {engagements.map(
                                    (engagement, engagementIndex) => (
                                        <tr
                                            key={engagement.id}
                                            className={cn(
                                                engagementIndex <
                                                    engagements.length - 1 &&
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
                                            <td className="px-4 py-3 text-right">
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
                    )}
                </div>
            </div>
        </>
    );
}
