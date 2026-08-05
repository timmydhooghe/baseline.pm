import { Head, setLayoutProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import WorkConnectDialog from '@/components/work-connect-dialog';
import WorkConnectionCard from '@/components/work-connection-card';
import WorkItemTable from '@/components/work-item-table';
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as workShow } from '@/routes/engagements/work';
import type {
    EngagementStatus,
    IntegrationConnectionView,
    ReleaseView,
    SelectOption,
    WorkItemView,
    WorkMappingSummary,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
        executionMode: string | null;
        executionModeLabel: string | null;
    };
    connections: IntegrationConnectionView[];
    workItems: WorkItemView[];
    releases: ReleaseView[];
    mapping: WorkMappingSummary;
    deliverables: { id: string; title: string }[];
    baselineVersion: number | null;
    providers: SelectOption[];
    states: SelectOption[];
    can: {
        manageIntegrations: boolean;
        recordWork: boolean;
        linkWork: boolean;
    };
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export default function EngagementsWork({
    engagement,
    connections,
    workItems,
    releases,
    mapping,
    deliverables,
    baselineVersion,
    providers,
    states,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Work', href: workShow(engagement.id) },
        ],
    });

    const stats = [
        { label: 'Work items', value: mapping.total, warn: false },
        { label: 'Mapped', value: mapping.linked, warn: false },
        {
            label: 'Unmapped',
            value: mapping.unlinked,
            warn: mapping.unlinked > 0,
        },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Work`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Execution work
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 text-[14px] text-stone dark:text-fog">
                            {engagement.executionModeLabel !== null
                                ? `${engagement.executionModeLabel} execution mode`
                                : 'No baseline yet'}
                            {baselineVersion !== null &&
                                ` · mapping against baseline v${baselineVersion}`}
                        </p>
                    </div>
                    <div className="flex gap-3" data-test="mapping-summary">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={cn(
                                    'border-[1.5px] px-3 py-2',
                                    stat.warn
                                        ? 'border-rust text-rust'
                                        : 'border-ink dark:border-paper',
                                )}
                            >
                                <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                    {stat.label}
                                </div>
                                <div className="font-plex-mono text-[20px] font-semibold">
                                    {stat.value}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {mapping.unlinked > 0 && (
                    <div className="border-[1.5px] border-rust px-4 py-3">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-rust uppercase">
                            {mapping.unlinked} unmapped{' '}
                            {mapping.unlinked === 1 ? 'item' : 'items'} —
                            unmapped work is potential scope creep. Map it to a
                            deliverable or triage it.
                        </span>
                    </div>
                )}

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Integrations
                        </h2>
                        {can.manageIntegrations && (
                            <WorkConnectDialog
                                engagementId={engagement.id}
                                providers={providers}
                                trigger={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="connect-integration-button"
                                    >
                                        Connect a tool
                                    </Button>
                                }
                            />
                        )}
                    </div>
                    {connections.length === 0 ? (
                        <div className="border-[1.5px] border-ink/40 px-4 py-4 dark:border-paper/40">
                            <p className="text-[13px] text-stone dark:text-fog">
                                No execution tool connected — work is recorded
                                manually (standalone mode). Connect Jira or
                                Linear whenever you are ready: the governance
                                history you build now carries over.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-4 lg:grid-cols-2">
                            {connections.map((connection) => (
                                <WorkConnectionCard
                                    key={connection.id}
                                    engagementId={engagement.id}
                                    connection={connection}
                                    providers={providers}
                                    canManage={can.manageIntegrations}
                                />
                            ))}
                        </div>
                    )}
                </section>

                <WorkItemTable
                    engagementId={engagement.id}
                    items={workItems}
                    deliverables={deliverables}
                    states={states}
                    canLink={can.linkWork && deliverables.length > 0}
                    canRecord={can.recordWork}
                />

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Releases
                        </span>
                    </div>
                    {releases.length === 0 ? (
                        <p className="px-4 py-4 text-[13px] text-stone dark:text-fog">
                            No releases synced yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Name</th>
                                        <th className={tableHeading}>Source</th>
                                        <th className={tableHeading}>Status</th>
                                        <th className={tableHeading}>
                                            Released
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {releases.map((release) => (
                                        <tr key={release.id}>
                                            <td className="px-4 py-2 font-medium">
                                                {release.externalUrl !==
                                                null ? (
                                                    <a
                                                        href={
                                                            release.externalUrl
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="hover:text-rust"
                                                    >
                                                        {release.name}
                                                    </a>
                                                ) : (
                                                    release.name
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-stone dark:text-fog">
                                                {release.sourceLabel}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span
                                                    className={cn(
                                                        'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase',
                                                        release.released
                                                            ? 'border-moss text-moss'
                                                            : 'border-stone text-stone dark:border-fog dark:text-fog',
                                                    )}
                                                >
                                                    {release.released
                                                        ? 'Released'
                                                        : 'Unreleased'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-stone dark:text-fog">
                                                {release.releasedOn ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
