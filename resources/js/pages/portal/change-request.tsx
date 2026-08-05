import { Head } from '@inertiajs/react';
import PortalDecisionForm from '@/components/portal-decision-form';
import type { PortalDecisionOption } from '@/components/portal-decision-form';
import PortalResponseList from '@/components/portal-response-list';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import type {
    ChangeRequestDecision,
    ChangeRequestProposalPayload,
    ChangeRequestResponseView,
    ChangeRequestStatus,
} from '@/types';

type Props = {
    proposal: ChangeRequestProposalPayload;
    changeRequest: {
        status: ChangeRequestStatus;
        statusLabel: string;
        respondBy: string | null;
        respondByOverdue: boolean;
        decidedAt: string | null;
    };
    stakeholder: { name: string };
    responses: ChangeRequestResponseView[];
    canRespond: boolean;
    respondUrl: string;
};

const decisionOptions: PortalDecisionOption<ChangeRequestDecision>[] = [
    {
        value: 'approved',
        title: 'Approve',
        description:
            'The change becomes part of the agreed baseline at the proposed terms.',
    },
    {
        value: 'rejected',
        title: 'Reject',
        description: 'The change is declined; the current baseline stands.',
    },
    {
        value: 'clarification_requested',
        title: 'Request clarification',
        description:
            'Send it back to the delivery team with a question — nothing is agreed yet.',
    },
];

export default function PortalChangeRequest({
    proposal,
    changeRequest,
    stakeholder,
    responses,
    canRespond,
    respondUrl,
}: Props) {
    const details = proposal.change_request;

    return (
        <>
            <Head title={`Change request — ${details.title}`} />
            <PortalLayout
                eyebrow={`Change request · ${details.engagement.name} · ${details.customer.name}`}
                title={details.title}
                intro={`Reviewing as ${stakeholder.name}. This proposal is frozen — your response is recorded immutably against exactly what you see here.`}
            >
                {changeRequest.status === 'approved' && (
                    <div className="border-[1.5px] border-moss px-4 py-3 font-plex-mono text-[12px] font-semibold text-moss uppercase">
                        Approved {changeRequest.decidedAt} — the change is part
                        of the agreed baseline.
                    </div>
                )}
                {changeRequest.status === 'rejected' && (
                    <div className="border-[1.5px] border-rust px-4 py-3 font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Rejected {changeRequest.decidedAt}.
                    </div>
                )}
                {canRespond && changeRequest.respondBy !== null && (
                    <div
                        className={cn(
                            'border-[1.5px] px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase',
                            changeRequest.respondByOverdue
                                ? 'border-rust text-rust'
                                : 'border-ink bg-sun/40',
                        )}
                    >
                        {changeRequest.respondByOverdue
                            ? `The response deadline of ${changeRequest.respondBy} has passed — please respond now.`
                            : `Please respond by ${changeRequest.respondBy}.`}
                    </div>
                )}

                <div className="border-[1.5px] border-ink">
                    <div className="border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>The change</span>
                    </div>
                    <div className="flex flex-col gap-3 px-4 py-4 text-[14px]">
                        <p>{details.what}</p>
                        {details.why !== null && (
                            <p className="text-stone">{details.why}</p>
                        )}
                        {proposal.scope.added !== null && (
                            <div>
                                <span className={portalSectionLabel}>
                                    Added
                                </span>
                                <p className="mt-1">{proposal.scope.added}</p>
                            </div>
                        )}
                        {proposal.scope.removed !== null && (
                            <div>
                                <span className={portalSectionLabel}>
                                    Removed
                                </span>
                                <p className="mt-1">{proposal.scope.removed}</p>
                            </div>
                        )}
                        {proposal.scope.alternatives !== null && (
                            <div>
                                <span className={portalSectionLabel}>
                                    Alternatives considered
                                </span>
                                <p className="mt-1">
                                    {proposal.scope.alternatives}
                                </p>
                            </div>
                        )}
                        {proposal.affected_items.length > 0 && (
                            <div>
                                <span className={portalSectionLabel}>
                                    Affected items
                                </span>
                                <ul className="mt-1 flex flex-wrap gap-1.5">
                                    {proposal.affected_items.map((item) => (
                                        <li
                                            key={item.id}
                                            className="border border-ink/40 px-2 py-0.5 text-[12px]"
                                        >
                                            {item.title}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                </div>

                <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-2">
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Price</div>
                        <div
                            className="mt-1 font-plex-mono text-[22px] font-bold"
                            data-test="proposal-price"
                        >
                            {proposal.price?.formatted ?? '—'}
                        </div>
                    </div>
                    <div className="px-4 py-3">
                        <div className={portalSectionLabel}>Schedule</div>
                        {proposal.schedule_impact === null ? (
                            <div className="mt-1 text-[14px] text-stone">
                                No milestone moves.
                            </div>
                        ) : (
                            <div className="mt-1 text-[14px]">
                                <span className="font-semibold">
                                    {proposal.schedule_impact.milestone.title}
                                </span>{' '}
                                moves{' '}
                                <span className="font-plex-mono font-semibold">
                                    {(proposal.schedule_impact.days ?? 0) >= 0
                                        ? '+'
                                        : ''}
                                    {proposal.schedule_impact.days ?? 0}d
                                </span>
                                {proposal.schedule_impact.projected_date !==
                                    null && (
                                    <>
                                        {' '}
                                        →{' '}
                                        {
                                            proposal.schedule_impact
                                                .projected_date
                                        }
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {canRespond ? (
                    <PortalDecisionForm
                        heading="Your decision"
                        options={decisionOptions}
                        defaultDecision="approved"
                        respondUrl={respondUrl}
                        submitLabel="Record my response →"
                        clarificationValue="clarification_requested"
                        footnote="Responses are immutable: a change of mind is a new record, never an edit."
                    />
                ) : (
                    <PortalResponseList responses={responses} />
                )}
            </PortalLayout>
        </>
    );
}
