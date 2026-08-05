import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import IntegrationConnectionController from '@/actions/App/Http/Controllers/IntegrationConnectionController';
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
import { index as organizationIntegrations } from '@/routes/organization/integrations';
import type { IntegrationAccountOption, IntegrationProvider } from '@/types';

type Props = {
    engagementId: string;
    accounts: IntegrationAccountOption[];
    /** Fix the provider (reconnect flow) instead of letting the user pick. */
    fixedProvider?: IntegrationProvider;
    defaultProjectKey?: string;
    canManageAccounts: boolean;
    trigger: ReactNode;
};

const fieldInput =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

export default function WorkConnectDialog({
    engagementId,
    accounts,
    fixedProvider,
    defaultProjectKey,
    canManageAccounts,
    trigger,
}: Props) {
    const [open, setOpen] = useState(false);
    const isReconnect = fixedProvider !== undefined;

    const available = isReconnect
        ? accounts.filter((account) => account.provider === fixedProvider)
        : accounts;

    const [accountId, setAccountId] = useState<string>(
        available.at(0)?.id ?? '',
    );
    const selected = available.find((account) => account.id === accountId);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {isReconnect
                        ? `Reconnect ${available.at(0)?.providerLabel ?? fixedProvider}`
                        : 'Connect an execution tool'}
                </DialogTitle>
                <DialogDescription>
                    {isReconnect
                        ? 'Pick a provider account to resync into the retained history — nothing that was imported is lost.'
                        : 'Issues, worklogs and releases sync both ways. Credentials come from your organization’s provider accounts and never leave the backend.'}
                </DialogDescription>
                {available.length === 0 ? (
                    <p
                        className="border-[1.5px] border-ink/40 px-4 py-3 text-[13px] text-stone dark:border-paper/40 dark:text-fog"
                        data-test="no-accounts-hint"
                    >
                        {isReconnect
                            ? 'No matching provider account is set up for your organization.'
                            : 'No provider accounts are set up for your organization yet.'}{' '}
                        {canManageAccounts ? (
                            <>
                                Add one under{' '}
                                <Link
                                    href={organizationIntegrations()}
                                    className="font-semibold text-rust hover:underline"
                                >
                                    Organization → Integrations
                                </Link>
                                .
                            </>
                        ) : (
                            'Ask your organization owner to add one under Organization → Integrations.'
                        )}
                    </p>
                ) : (
                    <Form
                        {...IntegrationConnectionController.store.form(
                            engagementId,
                        )}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                        resetOnSuccess
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="connect-account">
                                        Provider account
                                    </Label>
                                    <Select
                                        name="integration_account_id"
                                        value={accountId}
                                        onValueChange={setAccountId}
                                    >
                                        <SelectTrigger id="connect-account">
                                            <SelectValue placeholder="Pick an account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {available.map((account) => (
                                                <SelectItem
                                                    key={account.id}
                                                    value={account.id}
                                                >
                                                    {account.providerLabel} —{' '}
                                                    {account.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.integration_account_id}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="connect-project-key">
                                        {selected?.provider === 'linear'
                                            ? 'Team key'
                                            : 'Project key'}
                                    </Label>
                                    <Input
                                        id="connect-project-key"
                                        name="external_project_key"
                                        required
                                        placeholder="ENG"
                                        defaultValue={defaultProjectKey ?? ''}
                                        className={`${fieldInput} font-plex-mono uppercase`}
                                    />
                                    <InputError
                                        message={errors.external_project_key}
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
                                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                        data-test="connect-integration-submit"
                                    >
                                        {isReconnect
                                            ? 'Reconnect & resync →'
                                            : 'Connect & sync →'}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
