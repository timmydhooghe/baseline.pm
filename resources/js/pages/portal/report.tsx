import { Head } from '@inertiajs/react';
import ReportView from '@/components/report-view';
import PortalLayout from '@/layouts/portal-layout';
import type { ReportPayload } from '@/types';

type Props = {
    report: ReportPayload;
    meta: {
        weekLabel: string;
        publishedAt: string;
    };
    stakeholder: { name: string };
};

/**
 * A published weekly report as the customer reads it (FA-26): the frozen
 * customer snapshot — what moved, what changed, what is owed — with cost and
 * margin structurally absent. There is nothing to respond to; the record
 * simply is, and this link will always show exactly what was sent.
 */
export default function PortalReport({ report, meta, stakeholder }: Props) {
    return (
        <>
            <Head
                title={`Weekly report — ${report.engagement.name} · ${meta.weekLabel}`}
            />
            <PortalLayout
                eyebrow={`Weekly report · ${report.customer.name}`}
                title={report.engagement.name}
                intro={`${meta.weekLabel}, prepared for ${stakeholder.name}. Published ${meta.publishedAt} as a frozen record — every line below is drawn from the engagement's registers, and this page will always show exactly what was sent.`}
            >
                <ReportView report={report} />
            </PortalLayout>
        </>
    );
}
