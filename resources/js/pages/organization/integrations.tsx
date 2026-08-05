import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import IntegrationAccountController from '@/actions/App/Http/Controllers/IntegrationAccountController';
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
import { show as organization } from '@/routes/organization';
import { index as integrations } from '@/routes/organization/integrations';
import type { IntegrationProvider, SelectOption } from '@/types';

type AccountView = {
    id: string;
    provider: IntegrationProvider;
    providerLabel: string;
    name: string;
    baseUrl: string | null;
    inUseCount: number;
    createdByName: string | null;
    createdAt: string | null;
};

type Props = {
    accounts: AccountView[];
    providers: SelectOption[];
    can: { manage: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldInput =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const outlineButton =
    'rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper';

const submitButton =
    'rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper';

function AddAccountDialog({ providers }: { providers: SelectOption[] }) {
    const [open, setOpen] = useState(false);
    const [provider, setProvider] = useState<string>('jira');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={outlineButton}
                    data-test="add-account-button"
                >
                    Add account
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Add a provider account</DialogTitle>
                <DialogDescription>
                    The credentials are stored once, encrypted, and reused by
                    every engagement that connects through this account. They
                    never leave the backend.
                </DialogDescription>
                <Form
                    {...IntegrationAccountController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="account-provider">
                                    Provider
                                </Label>
                                <Select
                                    name="provider"
                                    value={provider}
                                    onValueChange={setProvider}
                                >
                                    <SelectTrigger id="account-provider">
                                        <SelectValue placeholder="Pick a tool" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {providers.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.provider} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="account-name">Name</Label>
                                <Input
                                    id="account-name"
                                    name="name"
                                    required
                                    placeholder={
                                        provider === 'linear'
                                            ? 'Studio Linear'
                                            : 'Studio Jira'
                                    }
                                    className={fieldInput}
                                />
                                <InputError message={errors.name} />
                            </div>

                            {provider === 'jira' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="account-base-url">
                                            Site URL
                                        </Label>
                                        <Input
                                            id="account-base-url"
                                            name="base_url"
                                            type="url"
                                            required
                                            placeholder="https://your-team.atlassian.net"
                                            className={fieldInput}
                                        />
                                        <InputError message={errors.base_url} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="account-email">
                                            Account email
                                        </Label>
                                        <Input
                                            id="account-email"
                                            name="email"
                                            type="email"
                                            required
                                            placeholder="pm@agency.com"
                                            className={fieldInput}
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="account-api-token">
                                    {provider === 'linear'
                                        ? 'API key'
                                        : 'API token'}
                                </Label>
                                <Input
                                    id="account-api-token"
                                    name="api_token"
                                    type="password"
                                    required
                                    autoComplete="off"
                                    className={`${fieldInput} font-plex-mono`}
                                />
                                <InputError message={errors.api_token} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className={submitButton}
                                    data-test="add-account-submit"
                                >
                                    Add account →
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditAccountDialog({
    account,
    onClose,
}: {
    account: AccountView;
    onClose: () => void;
}) {
    const isJira = account.provider === 'jira';

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogTitle>Edit {account.name}</DialogTitle>
                <DialogDescription>
                    Rename the account{isJira ? ', move its site URL,' : ''} or
                    rotate its credentials. Leave the{' '}
                    {isJira ? 'API token' : 'API key'} blank to keep the current
                    one.
                </DialogDescription>
                <Form
                    {...IntegrationAccountController.update.form(account.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-account-name">Name</Label>
                                <Input
                                    id="edit-account-name"
                                    name="name"
                                    required
                                    defaultValue={account.name}
                                    className={fieldInput}
                                />
                                <InputError message={errors.name} />
                            </div>

                            {isJira && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-account-base-url">
                                            Site URL
                                        </Label>
                                        <Input
                                            id="edit-account-base-url"
                                            name="base_url"
                                            type="url"
                                            required
                                            defaultValue={account.baseUrl ?? ''}
                                            className={fieldInput}
                                        />
                                        <InputError message={errors.base_url} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-account-email">
                                            Account email
                                        </Label>
                                        <Input
                                            id="edit-account-email"
                                            name="email"
                                            type="email"
                                            placeholder="Only needed when rotating the token"
                                            className={fieldInput}
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="edit-account-api-token">
                                    {isJira ? 'New API token' : 'New API key'}
                                </Label>
                                <Input
                                    id="edit-account-api-token"
                                    name="api_token"
                                    type="password"
                                    autoComplete="off"
                                    placeholder="Leave blank to keep the current one"
                                    className={`${fieldInput} font-plex-mono`}
                                />
                                <InputError message={errors.api_token} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className={submitButton}
                                    data-test="edit-account-submit"
                                >
                                    Save changes →
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function OrganizationIntegrations({
    accounts,
    providers,
    can,
}: Props) {
    const [accountToEdit, setAccountToEdit] = useState<AccountView | null>(
        null,
    );
    const [accountToRemove, setAccountToRemove] = useState<AccountView | null>(
        null,
    );

    return (
        <>
            <Head title="Integrations" />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Organization
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        Integrations
                    </h1>
                    <p className="mt-1 text-[14px] text-stone dark:text-fog">
                        Jira and Linear accounts engagements connect through —
                        each credential set is entered once and reused
                        everywhere.
                    </p>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            Provider accounts · {accounts.length}
                        </span>
                        {can.manage ? (
                            <AddAccountDialog providers={providers} />
                        ) : (
                            <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                Only the owner manages accounts
                            </span>
                        )}
                    </div>

                    {accounts.length === 0 ? (
                        <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                            No provider accounts yet. Add your Jira or Linear
                            credentials once here — engagements then connect by
                            picking an account, without ever re-entering a key.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[14px]">
                                <thead>
                                    <tr className="border-b-[1.5px] border-ink dark:border-paper">
                                        <th className={tableHeading}>Name</th>
                                        <th className={tableHeading}>
                                            Provider
                                        </th>
                                        <th className={tableHeading}>
                                            Site URL
                                        </th>
                                        <th className={tableHeading}>In use</th>
                                        <th className={tableHeading}>Added</th>
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
                                    {accounts.map((account, accountIndex) => (
                                        <tr
                                            key={account.id}
                                            className={cn(
                                                accountIndex <
                                                    accounts.length - 1 &&
                                                    'border-b border-ink/20 dark:border-paper/20',
                                            )}
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {account.name}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-block border-[1.5px] border-ink px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase dark:border-paper">
                                                    {account.providerLabel}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-plex-mono text-[12px] text-stone dark:text-fog">
                                                {account.baseUrl ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 font-plex-mono text-[12px] text-stone dark:text-fog">
                                                {account.inUseCount === 0
                                                    ? 'Unused'
                                                    : `${account.inUseCount} engagement${account.inUseCount === 1 ? '' : 's'}`}
                                            </td>
                                            <td className="px-4 py-3 text-[13px] text-stone dark:text-fog">
                                                {account.createdByName !== null
                                                    ? `${account.createdByName} · `
                                                    : ''}
                                                {account.createdAt ?? '—'}
                                            </td>
                                            {can.manage && (
                                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="rounded-none font-plex-mono text-[11px] font-semibold uppercase"
                                                        onClick={() =>
                                                            setAccountToEdit(
                                                                account,
                                                            )
                                                        }
                                                        data-test={`edit-account-${account.provider}`}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                        disabled={
                                                            account.inUseCount >
                                                            0
                                                        }
                                                        title={
                                                            account.inUseCount >
                                                            0
                                                                ? 'Disconnect the engagements syncing through this account first.'
                                                                : undefined
                                                        }
                                                        onClick={() =>
                                                            setAccountToRemove(
                                                                account,
                                                            )
                                                        }
                                                        data-test={`remove-account-${account.provider}`}
                                                    >
                                                        Remove
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {accountToEdit !== null && (
                <EditAccountDialog
                    account={accountToEdit}
                    onClose={() => setAccountToEdit(null)}
                />
            )}

            <Dialog
                open={accountToRemove !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setAccountToRemove(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>Remove {accountToRemove?.name}?</DialogTitle>
                    <DialogDescription>
                        The stored credentials are deleted. Engagement sync
                        history is never touched — only unused accounts can be
                        removed.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            data-test="confirm-remove-account"
                            onClick={() => {
                                if (accountToRemove === null) {
                                    return;
                                }

                                router.delete(
                                    IntegrationAccountController.destroy.url(
                                        accountToRemove.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            setAccountToRemove(null),
                                    },
                                );
                            }}
                        >
                            Remove account
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

OrganizationIntegrations.layout = {
    breadcrumbs: [
        {
            title: 'Organization',
            href: organization(),
        },
        {
            title: 'Integrations',
            href: integrations(),
        },
    ],
};
