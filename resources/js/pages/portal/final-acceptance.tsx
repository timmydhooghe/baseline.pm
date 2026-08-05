import { Head } from '@inertiajs/react';
import PortalDecisionForm from '@/components/portal-decision-form';
import type { PortalDecisionOption } from '@/components/portal-decision-form';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import type {
    AcceptanceDecision,
    FinalAcceptanceReviewPayload,
    FinalAcceptanceStatus,
} from '@/types';

type Props = {
    record: FinalAcceptanceReviewPayload;
    finalAcceptance: {
        status: FinalAcceptanceStatus;
        statusLabel: string;
        respondBy: string | null;
        respondByOverdue: boolean;
        decidedAt: string | null;
        decision: AcceptanceDecision | null;
        decisionLabel: string | null;
        decidedBy: string | null;
        comment: string | null;
    };
    stakeholder: { name: string };
    canRespond: boolean;
    respondUrl: string;
};

const decisionOptions: PortalDecisionOption<AcceptanceDecision>[] = [
    {
        value: 'accepted',
        title: 'Accept the engagement as complete',
        description:
            'You sign off every deliverable listed below and the engagement closes.',
    },
    {
        value: 'rejected',
        title: 'Reject',
        description:
            'The engagement is not complete; it returns to the delivery team.',
    },
    {
        value: 'clarification_requested',
        title: 'Request clarification',
        description:
            'Send it back with a question — the engagement stays open and nothing is signed.',
    },
];

export default function PortalFinalAcceptance({
    record,
    finalAcceptance,
    stakeholder,
    canRespond,
    respondUrl,
}: Props) {
    return (
        <>
            <Head title={`Final acceptance — ${record.engagement.name}`} />
            <PortalLayout
                eyebrow={`Final acceptance · ${record.engagement.customer.name}`}
                title={record.engagement.name}
                intro={`Reviewing as ${stakeholder.name}. Every deliverable below carries your team's signature already — this is the closing signature for the engagement as a whole.`}
            >
                {finalAcceptance.status === 'accepted' && (
                    <div className="border-[1.5px] border-moss px-4 py-3 font-plex-mono text-[12px] font-semibold text-moss uppercase">
                        Accepted {finalAcceptance.decidedAt} by{' '}
                        {finalAcceptance.decidedBy} — the engagement is
                        complete.
                    </div>
                )}
                {(finalAcceptance.status === 'rejected' ||
                    finalAcceptance.status === 'clarification_requested') && (
                    <div className="border-[1.5px] border-rust px-4 py-3 font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        {finalAcceptance.decisionLabel}{' '}
                        {finalAcceptance.decidedAt} — back with the delivery
                        team.
                    </div>
                )}
                {finalAcceptance.status === 'withdrawn' && (
                    <div className="border-[1.5px] border-ink/40 px-4 py-3 font-plex-mono text-[12px] font-semibold text-stone uppercase">
                        Withdrawn by the delivery team — no decision needed.
                    </div>
                )}
                {finalAcceptance.comment !== null && (
                    <p className="text-[13px] text-stone">
                        “{finalAcceptance.comment}”
                    </p>
                )}
                {canRespond && finalAcceptance.respondBy !== null && (
                    <div
                        className={cn(
                            'border-[1.5px] px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase',
                            finalAcceptance.respondByOverdue
                                ? 'border-rust text-rust'
                                : 'border-ink bg-sun/40',
                        )}
                    >
                        {finalAcceptance.respondByOverdue
                            ? `The response deadline of ${finalAcceptance.respondBy} has passed — please respond now.`
                            : `Please respond by ${finalAcceptance.respondBy}.`}
                    </div>
                )}

                <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-2">
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Contracted</div>
                        <div className="mt-1 font-plex-mono text-[22px] font-bold">
                            {record.contract_value?.formatted ?? '—'}
                        </div>
                        {record.baseline_version !== null && (
                            <div className="mt-1 text-[11px] text-stone">
                                baseline v{record.baseline_version}
                            </div>
                        )}
                    </div>
                    <div className="px-4 py-3">
                        <div className={portalSectionLabel}>Accepted</div>
                        <div
                            className="mt-1 font-plex-mono text-[22px] font-bold"
                            data-test="final-accepted-value"
                        >
                            {record.accepted_value.formatted}
                        </div>
                        <div className="mt-1 text-[11px] text-stone">
                            {record.deliverables.length} signed{' '}
                            {record.deliverables.length === 1
                                ? 'deliverable'
                                : 'deliverables'}
                        </div>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink">
                    <div className="border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>
                            Signed deliverables
                        </span>
                    </div>
                    <ul className="divide-y divide-ink/15">
                        {record.deliverables.map((deliverable) => (
                            <li
                                key={deliverable.id}
                                className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-[13px]"
                            >
                                <div className="flex flex-col">
                                    <span className="font-medium">
                                        {deliverable.title}
                                    </span>
                                    <span className="text-[12px] text-stone">
                                        {deliverable.accepted_by !== null
                                            ? `Accepted by ${deliverable.accepted_by}`
                                            : 'Accepted'}
                                        {deliverable.accepted_on !== null &&
                                            ` · ${deliverable.accepted_on}`}
                                    </span>
                                </div>
                                <span className="font-plex-mono font-semibold">
                                    {deliverable.value?.formatted ?? '—'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                {canRespond && (
                    <PortalDecisionForm
                        heading="Your decision"
                        options={decisionOptions}
                        defaultDecision="accepted"
                        respondUrl={respondUrl}
                        submitLabel="Record my response →"
                        clarificationValue="clarification_requested"
                        footnote="Your acceptance is recorded immutably against this frozen record and closes the engagement."
                    />
                )}
            </PortalLayout>
        </>
    );
}
