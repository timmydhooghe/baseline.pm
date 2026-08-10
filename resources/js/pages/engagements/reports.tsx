import { Head, Link, setLayoutProps } from '@inertiajs/react';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import {
    draft as reportDraft,
    index as reportsIndex,
} from '@/routes/engagements/reports';
import { show as reportShow } from '@/routes/reports';
import type {
    EngagementPositionSummary,
    EngagementStatus,
    ReportDueWeek,
    ReportListItem,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    due: ReportDueWeek[];
    published: ReportListItem[];
    position: EngagementPositionSummary;
    can: { publish: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

/**
 * The report ledger (FA-26): the weeks still owed a report, and every report
 * already published. Drafts are derived, never stored — a due week simply is
 * a draft — and a published week is a frozen record with its own page.
 */
export default function EngagementReports({
    engagement,
    due,
    published,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Reports', href: reportsIndex(engagement.id) },
        ],
        position,
    });

    return (
        <>
            <Head title={`${engagement.name} — Reports`} />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Weekly reports
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        {engagement.name}
                    </h1>
                    <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                        Every report is drafted from the engagement's own
                        records — what moved, what changed, what is owed — and
                        publishing freezes it and sends the customer's
                        stakeholders their copy, cost and margin left out.
                    </p>
                </div>

                {due.length > 0 && (
                    <div className="border-[1.5px] border-ochre">
                        <div className="border-b-[1.5px] border-ochre px-4 py-3">
                            <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-ochre uppercase">
                                Awaiting publication · {due.length}
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                            {due.map((week) => (
                                <li
                                    key={week.weekStart}
                                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-[13px]"
                                    data-test={`due-report-${week.weekStart}`}
                                >
                                    <span className="font-plex-mono">
                                        {week.weekLabel}
                                    </span>
                                    <Link
                                        href={reportDraft([
                                            engagement.id,
                                            week.weekStart,
                                        ])}
                                        prefetch
                                        className="font-plex-mono text-[11px] font-semibold uppercase hover:text-rust"
                                    >
                                        {can.publish
                                            ? 'Open the draft →'
                                            : 'Read the draft →'}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            Published · {published.length}
                        </span>
                    </div>
                    {published.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Nothing published yet — the first report freezes
                            once a finished week is published.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Week</th>
                                        <th className={tableHeading}>
                                            Published
                                        </th>
                                        <th className={tableHeading}>By</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {published.map((report) => (
                                        <tr
                                            key={report.id}
                                            data-test={`published-report-${report.weekStart}`}
                                        >
                                            <td className="px-4 py-2">
                                                <Link
                                                    href={reportShow(report.id)}
                                                    prefetch
                                                    className="font-medium hover:text-rust"
                                                >
                                                    {report.weekLabel}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono">
                                                {report.publishedAt}
                                            </td>
                                            <td className="px-4 py-2">
                                                {report.publishedByName ?? '—'}
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
