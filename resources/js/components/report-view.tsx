import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { ReportMovedLine, ReportPayload, ReportRecordChip } from '@/types';

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

/**
 * One report, rendered from its payload — a live draft and a frozen snapshot
 * read identically, which is the point: publishing changes nothing but the
 * ink. Internal chips carry a server-resolved link to the record they derive
 * from; the portal renders the same lines with nowhere to click, because the
 * records behind them are not the customer's to open.
 */
export default function ReportView({ report }: { report: ReportPayload }) {
    return (
        <div
            className="flex flex-col gap-6"
            data-test={`report-${report.kind}`}
        >
            <div className="border-[1.5px] border-ink px-4 py-3 font-plex-mono text-[12px] dark:border-paper">
                {report.previous === null ? (
                    <span className="text-stone dark:text-fog">
                        First report — nothing earlier to diff against.
                    </span>
                ) : (
                    <span>
                        Diffed against the published report for{' '}
                        <span className="font-semibold">
                            {report.previous.week_label}
                        </span>
                        .
                    </span>
                )}
                {report.baseline !== null && (
                    <span className="block text-stone dark:text-fog">
                        Baseline v{report.baseline.version} ·{' '}
                        {report.baseline.contract_value.formatted} contracted
                    </span>
                )}
            </div>

            <section className="border-[1.5px] border-ink dark:border-paper">
                <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                    <span className={sectionLabel}>What moved</span>
                </div>
                {report.moved.length === 0 ? (
                    <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                        No deliverables on the baseline yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-[13px]">
                            <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                <tr>
                                    <th className={tableHeading}>
                                        Deliverable
                                    </th>
                                    <th className={tableHeading}>Progress</th>
                                    <th className={tableHeading}>Status</th>
                                    <th className={tableHeading}>Forecast</th>
                                    <th className={tableHeading}>Value</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                {report.moved.map((line) => (
                                    <tr
                                        key={line.record.id}
                                        data-test={`report-moved-${line.record.id}`}
                                    >
                                        <td className="px-4 py-2">
                                            <RecordTitle record={line.record} />
                                            {line.milestone !== null && (
                                                <span className="block font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                    {line.milestone}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 font-plex-mono font-semibold">
                                            {line.progress}%
                                            <ProgressDelta line={line} />
                                        </td>
                                        <td className="px-4 py-2 font-plex-mono text-[11px] uppercase">
                                            <span
                                                className={cn(
                                                    line.status ===
                                                        'accepted' &&
                                                        'text-moss',
                                                    line.status ===
                                                        'rejected' &&
                                                        'text-rust',
                                                )}
                                            >
                                                {line.status_label}
                                            </span>
                                            {line.previous !== null &&
                                                line.previous.status !== null &&
                                                line.previous.status !==
                                                    line.status && (
                                                    <span className="block text-stone dark:text-fog">
                                                        was{' '}
                                                        {
                                                            line.previous
                                                                .status_label
                                                        }
                                                    </span>
                                                )}
                                        </td>
                                        <td className="px-4 py-2 font-plex-mono">
                                            {line.accepted_at !== null
                                                ? `Signed ${line.accepted_at}`
                                                : (line.forecast_date ?? '—')}
                                        </td>
                                        <td className="px-4 py-2 font-plex-mono">
                                            {line.value?.formatted ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="border-[1.5px] border-ink dark:border-paper">
                <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                    <span className={sectionLabel}>What changed this week</span>
                </div>
                {report.changed.length === 0 ? (
                    <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                        A quiet week — nothing was decided, submitted or
                        re-rated.
                    </p>
                ) : (
                    <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                        {report.changed.map((line, index) => (
                            <li
                                key={`${line.event}-${line.record.id}-${index}`}
                                className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2 text-[13px]"
                                data-test={`report-changed-${line.event}-${line.record.id}`}
                            >
                                <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                    {line.date}
                                </span>
                                <span className="font-plex-mono text-[11px] font-semibold uppercase">
                                    {line.event_label}
                                </span>
                                <RecordTitle record={line.record} />
                                {line.detail !== null && (
                                    <span className="text-stone dark:text-fog">
                                        {line.detail}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="border-[1.5px] border-ink dark:border-paper">
                <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                    <span className={sectionLabel}>
                        What is owed, and by whom
                    </span>
                </div>
                {report.owed.length === 0 ? (
                    <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                        Nothing outstanding — nobody owes the engagement
                        anything.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-[13px]">
                            <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                <tr>
                                    <th className={tableHeading}>Item</th>
                                    <th className={tableHeading}>Owed by</th>
                                    <th className={tableHeading}>Required</th>
                                    <th className={tableHeading}>Delay</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                {report.owed.map((line) => (
                                    <tr
                                        key={line.record.id}
                                        data-test={`report-owed-${line.record.id}`}
                                    >
                                        <td className="px-4 py-2">
                                            <RecordTitle record={line.record} />
                                            <span className="block font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                {line.status_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            {line.responsible ?? '—'}
                                            <span className="block font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                {line.party_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 font-plex-mono">
                                            {line.required_on}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-4 py-2 font-plex-mono font-semibold',
                                                line.late && 'text-rust',
                                            )}
                                        >
                                            {line.delay_days === 0
                                                ? '—'
                                                : `${line.delay_days} d`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {report.commercials !== undefined && (
                <Commercials report={report} />
            )}
        </div>
    );
}

function RecordTitle({ record }: { record: ReportRecordChip }) {
    if (record.href !== undefined && record.href !== null) {
        return (
            <Link
                href={record.href}
                prefetch
                className="font-medium hover:text-rust"
            >
                {record.title}
            </Link>
        );
    }

    return <span className="font-medium">{record.title}</span>;
}

function ProgressDelta({ line }: { line: ReportMovedLine }) {
    if (line.previous === null || line.previous.progress === null) {
        return null;
    }

    const delta = line.progress - line.previous.progress;

    if (delta === 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'ml-1 font-plex-mono text-[11px] font-semibold',
                delta > 0 ? 'text-moss' : 'text-rust',
            )}
        >
            {delta > 0 ? `+${delta}` : delta}
        </span>
    );
}

/**
 * The internal money block: the position as published, read against what the
 * previous report said. Structurally absent from customer payloads and from
 * viewers without rate card access — this component simply never mounts.
 */
function Commercials({ report }: { report: ReportPayload }) {
    const commercials = report.commercials;

    if (commercials === undefined) {
        return null;
    }

    const { position, burn_week: burnWeek, previous } = commercials;

    const blocks = [
        {
            label: 'Recorded burn',
            value: position.burn?.recorded.formatted ?? '—',
            note:
                previous?.recorded_burn != null
                    ? `was ${previous.recorded_burn.formatted}`
                    : null,
        },
        {
            label: 'This week',
            value: burnWeek?.cost.formatted ?? 'Not recorded',
            note: burnWeek !== null ? `${burnWeek.days} days` : null,
        },
        {
            label: 'Margin forecast',
            value:
                position.margin?.percent != null
                    ? `${position.margin.percent}%`
                    : '—',
            note:
                position.margin?.percent != null &&
                previous?.margin_percent != null
                    ? `was ${previous.margin_percent}%`
                    : null,
        },
        {
            label: 'Unbilled risk',
            value: position.unbilledRisk.price?.formatted ?? '—',
            note: `${position.unbilledRisk.count} items`,
        },
    ];

    return (
        <section
            className="border-[1.5px] border-ink dark:border-paper"
            data-test="report-commercials"
        >
            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className={sectionLabel}>
                    Commercial position · internal only
                </span>
            </div>
            <div className="grid gap-0 sm:grid-cols-4">
                {blocks.map((block, index) => (
                    <div
                        key={block.label}
                        className={cn(
                            'px-4 py-3',
                            index < blocks.length - 1 &&
                                'border-ink/20 sm:border-r dark:border-paper/20',
                        )}
                    >
                        <div className={sectionLabel}>{block.label}</div>
                        <div className="mt-1 font-plex-mono text-[20px] font-semibold">
                            {block.value}
                        </div>
                        {block.note !== null && (
                            <div className="mt-1 text-[11px] text-stone dark:text-fog">
                                {block.note}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}
