import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as auditShow } from '@/routes/engagements/audit';
import type {
    AuditEntry,
    EngagementPositionSummary,
    EngagementStatus,
} from '@/types';

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    entries: Paginated<AuditEntry>;
    actions: string[];
    filters: { action: string; subject: string };
    position: EngagementPositionSummary;
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

/**
 * The engagement's audit trail (FA-21): append-only, in order, and filtered
 * down to a single record when a ledger links here asking "what happened to
 * this?".
 */
export default function EngagementAudit({
    engagement,
    entries,
    actions,
    filters,
    position,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Audit trail', href: auditShow(engagement.id) },
        ],
        position,
    });

    const filterBy = (action: string) =>
        router.get(
            auditShow(engagement.id).url,
            action === '' ? {} : { action },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title={`${engagement.name} — Audit trail`} />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Audit trail
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        {engagement.name}
                    </h1>
                    <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                        Every governance action on this engagement, appended and
                        never rewritten. {entries.total} entries on record.
                    </p>
                </div>

                {filters.subject !== '' && (
                    <div className="flex flex-wrap items-center gap-3 border-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[12px] font-semibold uppercase">
                            Filtered to one record
                        </span>
                        <Link
                            href={auditShow(engagement.id)}
                            className="font-plex-mono text-[12px] underline hover:text-rust"
                        >
                            Show the whole trail
                        </Link>
                    </div>
                )}

                {actions.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={() => filterBy('')}
                            className={cn(
                                'border-[1.5px] px-2 py-1 font-plex-mono text-[11px] font-semibold uppercase',
                                filters.action === ''
                                    ? 'border-ink bg-ink text-paper dark:border-paper dark:bg-paper dark:text-ink'
                                    : 'border-ink/40 text-stone hover:border-ink dark:border-paper/40 dark:text-fog',
                            )}
                        >
                            Everything
                        </button>
                        {actions.map((action) => (
                            <button
                                key={action}
                                type="button"
                                onClick={() => filterBy(action)}
                                className={cn(
                                    'border-[1.5px] px-2 py-1 font-plex-mono text-[11px] font-semibold uppercase',
                                    filters.action === action
                                        ? 'border-ink bg-ink text-paper dark:border-paper dark:bg-paper dark:text-ink'
                                        : 'border-ink/40 text-stone hover:border-ink dark:border-paper/40 dark:text-fog',
                                )}
                            >
                                {action}
                            </button>
                        ))}
                    </div>
                )}

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>The trail</span>
                    </div>
                    {entries.data.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Nothing recorded for this filter.
                        </p>
                    ) : (
                        <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                            {entries.data.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex flex-col gap-1 px-4 py-3"
                                    data-test={`audit-entry-${entry.id}`}
                                >
                                    <div className="flex flex-wrap items-baseline gap-2">
                                        <span className="font-plex-mono text-[12px] font-semibold uppercase">
                                            {entry.action}
                                        </span>
                                        <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                            {entry.subjectType} ·{' '}
                                            {entry.recordedAt}
                                            {entry.actorName !== null
                                                ? ` · ${entry.actorName}`
                                                : ' · system'}
                                        </span>
                                    </div>
                                    {entry.payload !== null && (
                                        <pre className="overflow-x-auto font-plex-mono text-[11px] whitespace-pre-wrap text-stone dark:text-fog">
                                            {JSON.stringify(
                                                entry.payload,
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {entries.links.length > 3 && (
                    <div className="flex flex-wrap gap-1.5">
                        {entries.links.map((link) => (
                            <Link
                                key={link.label}
                                href={link.url ?? '#'}
                                preserveState
                                className={cn(
                                    'border-[1.5px] px-2 py-1 font-plex-mono text-[11px] font-semibold',
                                    link.active
                                        ? 'border-ink bg-ink text-paper dark:border-paper dark:bg-paper dark:text-ink'
                                        : 'border-ink/40 text-stone dark:border-paper/40 dark:text-fog',
                                    link.url === null &&
                                        'pointer-events-none opacity-40',
                                )}
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
