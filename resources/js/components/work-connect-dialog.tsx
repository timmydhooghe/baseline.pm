import { Form } from '@inertiajs/react';
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
import type { IntegrationProvider, SelectOption } from '@/types';

type Props = {
    engagementId: string;
    providers: SelectOption[];
    /** Fix the provider (reconnect flow) instead of letting the user pick. */
    fixedProvider?: IntegrationProvider;
    defaultProjectKey?: string;
    defaultBaseUrl?: string | null;
    trigger: ReactNode;
};

const fieldInput =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

export default function WorkConnectDialog({
    engagementId,
    providers,
    fixedProvider,
    defaultProjectKey,
    defaultBaseUrl,
    trigger,
}: Props) {
    const [open, setOpen] = useState(false);
    const [provider, setProvider] = useState<string>(fixedProvider ?? 'jira');
    const isReconnect = fixedProvider !== undefined;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {isReconnect
                        ? `Reconnect ${providers.find((option) => option.value === fixedProvider)?.label ?? fixedProvider}`
                        : 'Connect an execution tool'}
                </DialogTitle>
                <DialogDescription>
                    {isReconnect
                        ? 'Fresh credentials resync into the retained history — nothing that was imported is lost.'
                        : 'Issues, worklogs and releases sync both ways. Credentials are stored encrypted and never leave the backend.'}
                </DialogDescription>
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
                                <Label htmlFor="connect-provider">
                                    Provider
                                </Label>
                                {isReconnect ? (
                                    <input
                                        type="hidden"
                                        name="provider"
                                        value={fixedProvider}
                                    />
                                ) : null}
                                <Select
                                    name={isReconnect ? undefined : 'provider'}
                                    value={provider}
                                    onValueChange={setProvider}
                                    disabled={isReconnect}
                                >
                                    <SelectTrigger id="connect-provider">
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
                                <Label htmlFor="connect-project-key">
                                    {provider === 'linear'
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

                            {provider === 'jira' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="connect-base-url">
                                            Site URL
                                        </Label>
                                        <Input
                                            id="connect-base-url"
                                            name="base_url"
                                            type="url"
                                            required
                                            placeholder="https://your-team.atlassian.net"
                                            defaultValue={defaultBaseUrl ?? ''}
                                            className={fieldInput}
                                        />
                                        <InputError message={errors.base_url} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="connect-email">
                                            Account email
                                        </Label>
                                        <Input
                                            id="connect-email"
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
                                <Label htmlFor="connect-api-token">
                                    {provider === 'linear'
                                        ? 'API key'
                                        : 'API token'}
                                </Label>
                                <Input
                                    id="connect-api-token"
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
            </DialogContent>
        </Dialog>
    );
}
