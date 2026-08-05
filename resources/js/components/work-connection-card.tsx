import { router } from '@inertiajs/react';
import IntegrationConnectionController from '@/actions/App/Http/Controllers/IntegrationConnectionController';
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
import WorkConnectDialog from '@/components/work-connect-dialog';
import { cn } from '@/lib/utils';
import type {
    IntegrationConnectionView,
    SelectOption,
    SyncRunView,
} from '@/types';

type Props = {
    engagementId: string;
    connection: IntegrationConnectionView;
    providers: SelectOption[];
    canManage: boolean;
};

const runStatusClasses: Record<SyncRunView['status'], string> = {
    running: 'border-ochre text-ochre',
    succeeded: 'border-moss text-moss',
    failed: 'border-rust text-rust',
};

function runCounts(counts: Record<string, number> | null) {
    if (counts === null) {
        return null;
    }

    return [
        `${counts.work_items ?? 0} items`,
        `${counts.worklogs ?? 0} worklogs`,
        `${counts.releases ?? 0} releases`,
    ].join(' · ');
}

export default function WorkConnectionCard({
    engagementId,
    connection,
    providers,
    canManage,
}: Props) {
    const isConnected = connection.status === 'connected';

    const syncNow = () =>
        router.post(
            IntegrationConnectionController.sync.url(connection.id),
            {},
            { preserveScroll: true },
        );

    const disconnect = () =>
        router.post(
            IntegrationConnectionController.disconnect.url(connection.id),
            {},
            { preserveScroll: true },
        );

    return (
        <div
            className="border-[1.5px] border-ink dark:border-paper"
            data-test={`connection-card-${connection.provider}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                    {connection.providerLabel} ·{' '}
                    <span className="text-stone dark:text-fog">
                        {connection.externalProjectKey}
                    </span>
                </span>
                <span
                    className={cn(
                        'border-[1.5px] px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase',
                        isConnected
                            ? 'border-moss text-moss'
                            : 'border-stone text-stone dark:border-fog dark:text-fog',
                    )}
                    data-test={`connection-status-${connection.provider}`}
                >
                    {connection.statusLabel}
                </span>
            </div>

            <div className="flex flex-col gap-3 px-4 py-3">
                <p className="text-[13px] text-stone dark:text-fog">
                    {isConnected ? (
                        <>
                            {connection.lastSyncedAt === null
                                ? 'First sync queued — status appears here.'
                                : `Last synced ${connection.lastSyncedAt}.`}
                            {connection.connectedByName !== null &&
                                ` Connected by ${connection.connectedByName}`}
                            {connection.connectedAt !== null &&
                                ` on ${connection.connectedAt}.`}
                        </>
                    ) : (
                        <>
                            Disconnected
                            {connection.disconnectedAt !== null &&
                                ` on ${connection.disconnectedAt}`}
                            . The imported history is retained — reconnect to
                            resync.
                        </>
                    )}
                </p>

                {connection.runs.length > 0 && (
                    <ul className="flex flex-col divide-y divide-ink/15 border-[1.5px] border-ink/30 dark:divide-paper/15 dark:border-paper/30">
                        {connection.runs.map((run) => (
                            <li
                                key={run.id}
                                className="flex flex-wrap items-center gap-2 px-3 py-2"
                            >
                                <span
                                    className={cn(
                                        'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase',
                                        runStatusClasses[run.status],
                                    )}
                                >
                                    {run.statusLabel}
                                </span>
                                <span className="text-[12px] text-stone dark:text-fog">
                                    {run.startedAt}
                                </span>
                                <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                    {run.status === 'failed'
                                        ? run.error
                                        : runCounts(run.counts)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        {isConnected ? (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                    onClick={syncNow}
                                    data-test={`sync-now-${connection.provider}`}
                                >
                                    Sync now
                                </Button>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                            data-test={`disconnect-${connection.provider}`}
                                        >
                                            Disconnect
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>
                                            Disconnect{' '}
                                            {connection.providerLabel}?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Syncing stops and the credentials
                                            are wiped. Everything already
                                            imported is retained, and
                                            reconnecting later resyncs into the
                                            same history.
                                        </DialogDescription>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                onClick={disconnect}
                                                data-test={`confirm-disconnect-${connection.provider}`}
                                            >
                                                Disconnect
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </>
                        ) : (
                            <WorkConnectDialog
                                engagementId={engagementId}
                                providers={providers}
                                fixedProvider={connection.provider}
                                defaultProjectKey={
                                    connection.externalProjectKey
                                }
                                defaultBaseUrl={connection.baseUrl}
                                trigger={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test={`reconnect-${connection.provider}`}
                                    >
                                        Reconnect →
                                    </Button>
                                }
                            />
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
