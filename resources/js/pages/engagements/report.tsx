import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/ReportController';
import ReportView from '@/components/report-view';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as reportsIndex } from '@/routes/engagements/reports';
import type {
    EngagementPositionSummary,
    EngagementStatus,
    ReportMeta,
    ReportPayload,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    report: ReportMeta;
    variants: {
        internal: ReportPayload;
        customer: ReportPayload;
    };
    position: EngagementPositionSummary;
    can: { publish: boolean };
};

/**
 * One week's report (FA-26) — a live draft derived from the ledgers, or the
 * frozen record of what was published. The internal and customer variants
 * render side by side behind a toggle, so what the customer will read is
 * never a surprise: the customer variant is built without cost or margin,
 * not merely hidden.
 */
export default function EngagementReport({
    engagement,
    report,
    variants,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Reports', href: reportsIndex(engagement.id) },
            { title: report.weekLabel },
        ],
        position,
    });

    const [variant, setVariant] = useState<'internal' | 'customer'>('internal');

    const variantButton = (key: 'internal' | 'customer', label: string) => (
        <button
            type="button"
            onClick={() => setVariant(key)}
            className={cn(
                'px-3 py-1.5 font-plex-mono text-[11px] font-semibold uppercase',
                variant === key
                    ? 'bg-ink text-paper dark:bg-paper dark:text-ink'
                    : 'hover:text-rust',
            )}
            data-test={`report-variant-${key}`}
        >
            {label}
        </button>
    );

    return (
        <>
            <Head title={`${engagement.name} — Report ${report.weekLabel}`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            {report.published
                                ? 'Published report'
                                : 'Report draft'}
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {report.weekLabel}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            {report.published
                                ? `Frozen on ${report.publishedAt}${report.publishedByName === null ? '' : ` by ${report.publishedByName}`} — this page always shows exactly what was sent.`
                                : "Drafted live from the engagement's records — nothing here is typed, and publishing freezes it exactly as shown."}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="flex border-[1.5px] border-ink dark:border-paper">
                            {variantButton('internal', 'Internal')}
                            {variantButton('customer', 'Customer')}
                        </div>
                        {!report.published && can.publish && (
                            <Form
                                {...ReportController.store.form(engagement.id)}
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="week_start"
                                            value={report.weekStart}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-none font-semibold shadow-none"
                                            data-test="publish-report"
                                        >
                                            {processing
                                                ? 'Publishing…'
                                                : 'Publish & send'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                {variant === 'customer' && (
                    <div className="border-[1.5px] border-ink bg-sun/40 px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase dark:border-paper dark:bg-transparent">
                        Customer variant — cost, margin and internal records are
                        structurally absent, not hidden.
                    </div>
                )}

                <ReportView report={variants[variant]} />
            </div>
        </>
    );
}
