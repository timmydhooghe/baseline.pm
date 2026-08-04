import { Form, Head, Link } from '@inertiajs/react';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
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
import { index as customers, show as customerShow } from '@/routes/customers';

type CustomerListItem = {
    id: string;
    name: string;
    stakeholderCount: number;
    engagementCount: number;
};

type Props = {
    customers: CustomerListItem[];
    can: { manage: boolean };
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export default function CustomersIndex({ customers, can }: Props) {
    return (
        <>
            <Head title="Customers" />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Customers
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            Customer records
                        </h1>
                    </div>

                    {can.manage && (
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                    data-test="new-customer-button"
                                >
                                    New customer
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>New customer</DialogTitle>
                                <DialogDescription>
                                    A customer record holds the external
                                    stakeholders and engagements for one client.
                                    Client users are always free.
                                </DialogDescription>
                                <Form
                                    {...CustomerController.store.form()}
                                    resetOnSuccess
                                    className="space-y-6"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="customer-name">
                                                    Name
                                                </Label>
                                                <Input
                                                    id="customer-name"
                                                    name="name"
                                                    required
                                                    autoFocus
                                                    placeholder="Acme Industries"
                                                />
                                                <InputError
                                                    message={errors.name}
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
                                                    data-test="create-customer-button"
                                                >
                                                    Create customer
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                {customers.length === 0 ? (
                    <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                        <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                            No customers yet
                        </div>
                        <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                            Create a customer record to add its stakeholders and
                            start engagements for it.
                        </p>
                    </div>
                ) : (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <table className="w-full text-left text-[14px]">
                            <thead>
                                <tr className="border-b-[1.5px] border-ink dark:border-paper">
                                    <th className={tableHeading}>Name</th>
                                    <th className={tableHeading}>
                                        Stakeholders
                                    </th>
                                    <th className={tableHeading}>
                                        Engagements
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.map((customer, customerIndex) => (
                                    <tr
                                        key={customer.id}
                                        className={cn(
                                            customerIndex <
                                                customers.length - 1 &&
                                                'border-b border-ink/20 dark:border-paper/20',
                                        )}
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={customerShow(customer.id)}
                                                prefetch
                                                className="hover:text-rust"
                                            >
                                                {customer.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 font-plex-mono text-[13px] text-stone dark:text-fog">
                                            {customer.stakeholderCount}
                                        </td>
                                        <td className="px-4 py-3 font-plex-mono text-[13px] text-stone dark:text-fog">
                                            {customer.engagementCount}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Customers',
            href: customers(),
        },
    ],
};
