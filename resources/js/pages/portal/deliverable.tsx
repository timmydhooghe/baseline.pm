import { Head } from '@inertiajs/react';
import PortalDecisionForm from '@/components/portal-decision-form';
import type { PortalDecisionOption } from '@/components/portal-decision-form';
import PortalResponseList from '@/components/portal-response-list';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import type {
    AcceptanceDecision,
    DeliverableResponseView,
    DeliverableReviewPayload,
    DeliverableStatus,
} from '@/types';

type Props = {
    review: DeliverableReviewPayload;
    deliverable: {
        status: DeliverableStatus;
        statusLabel: string;
        respondBy: string | null;
        respondByOverdue: boolean;
        acceptedAt: string | null;
        decidedAt: string | null;
    };
    stakeholder: { name: string };
    responses: DeliverableResponseView[];
    canRespond: boolean;
    respondUrl: string;
};

const decisionOptions: PortalDecisionOption<AcceptanceDecision>[] = [
    {
        value: 'accepted',
        title: 'Accept',
        description:
            'You sign off the deliverable as delivered against its acceptance criteria.',
    },
    {
        value: 'rejected',
        title: 'Reject',
        description:
            'The deliverable is not accepted; the delivery team reworks and resubmits it.',
    },
    {
        value: 'clarification_requested',
        title: 'Request clarification',
        description:
            'Send it back with a question — nothing is accepted or rejected yet.',
    },
];

export default function PortalDeliverable({
    review,
    deliverable,
    stakeholder,
    responses,
    canRespond,
    respondUrl,
}: Props) {
    const details = review.deliverable;

    return (
        <>
            <Head title={`Deliverable — ${details.title}`} />
            <PortalLayout
                eyebrow={`Deliverable review · ${details.engagement.name} · ${details.customer.name}`}
                title={details.title}
                intro={`Reviewing as ${stakeholder.name}. This record is frozen — your response is recorded immutably against exactly what you see here, and accepting is signing.`}
            >
                {deliverable.status === 'accepted' && (
                    <div className="border-[1.5px] border-moss px-4 py-3 font-plex-mono text-[12px] font-semibold text-moss uppercase">
                        Accepted {deliverable.acceptedAt} — signed off as
                        delivered.
                    </div>
                )}
                {deliverable.status === 'rejected' && (
                    <div className="border-[1.5px] border-rust px-4 py-3 font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Rejected {deliverable.decidedAt} — with the delivery
                        team for rework.
                    </div>
                )}
                {canRespond && deliverable.respondBy !== null && (
                    <div
                        className={cn(
                            'border-[1.5px] px-4 py-3 font-plex-mono text-[12px] font-semibold uppercase',
                            deliverable.respondByOverdue
                                ? 'border-rust text-rust'
                                : 'border-ink bg-sun/40',
                        )}
                    >
                        {deliverable.respondByOverdue
                            ? `The response deadline of ${deliverable.respondBy} has passed — please respond now.`
                            : `Please respond by ${deliverable.respondBy}.`}
                    </div>
                )}

                <div className="border-[1.5px] border-ink">
                    <div className="border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>
                            What was delivered
                        </span>
                    </div>
                    <div className="flex flex-col gap-3 px-4 py-4 text-[14px]">
                        {details.description !== null && (
                            <p>{details.description}</p>
                        )}
                        <p className="text-[12px] text-stone">
                            {details.clause_reference} · baseline v
                            {details.baseline_version}
                        </p>
                    </div>
                </div>

                <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-3">
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Value</div>
                        <div
                            className="mt-1 font-plex-mono text-[22px] font-bold"
                            data-test="review-value"
                        >
                            {review.value?.formatted ?? '—'}
                        </div>
                    </div>
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Progress</div>
                        <div className="mt-1 font-plex-mono text-[22px] font-bold">
                            {review.progress}%
                        </div>
                    </div>
                    <div className="px-4 py-3">
                        <div className={portalSectionLabel}>Milestone</div>
                        <div className="mt-1 text-[14px]">
                            {review.milestone === null ? (
                                <span className="text-stone">Unassigned</span>
                            ) : (
                                <>
                                    <span className="font-semibold">
                                        {review.milestone.title}
                                    </span>
                                    {review.milestone.baseline_date !==
                                        null && (
                                        <span className="block text-[12px] text-stone">
                                            {review.milestone.baseline_date}
                                        </span>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink">
                    <div className="border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>
                            Acceptance criteria &amp; evidence
                        </span>
                    </div>
                    {review.acceptance_criteria.length === 0 ? (
                        <p className="px-4 py-4 text-[13px] text-stone">
                            This deliverable carries no acceptance criteria.
                        </p>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {review.acceptance_criteria.map(
                                (criterion, index) => (
                                    <li
                                        key={`${criterion.criterion}-${index}`}
                                        className="flex flex-col gap-1 px-4 py-3 text-[13px]"
                                        data-test={`review-criterion-${index}`}
                                    >
                                        <span className="font-medium">
                                            {criterion.criterion}
                                        </span>
                                        {criterion.verification_method !==
                                            null && (
                                            <span className="text-stone">
                                                Verified by{' '}
                                                {criterion.verification_method}
                                            </span>
                                        )}
                                        {criterion.evidence === null ? (
                                            <span className="text-[12px] text-stone">
                                                No evidence shared.
                                            </span>
                                        ) : criterion.evidence.url === null ? (
                                            <span className="text-[12px] font-medium">
                                                {criterion.evidence.label}
                                            </span>
                                        ) : (
                                            <a
                                                href={criterion.evidence.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="w-fit text-[12px] font-medium underline hover:text-rust"
                                            >
                                                {criterion.evidence.label}
                                            </a>
                                        )}
                                    </li>
                                ),
                            )}
                        </ul>
                    )}
                </div>

                {review.evidence.length > 0 && (
                    <div className="border-[1.5px] border-ink">
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>
                                Shared evidence
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15">
                            {review.evidence.map((evidence, index) => (
                                <li
                                    key={`${evidence.label}-${index}`}
                                    className="flex flex-wrap items-center gap-2 px-4 py-3 text-[13px]"
                                >
                                    <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase">
                                        {evidence.kind.replace('_', ' ')}
                                    </span>
                                    {evidence.url === null ? (
                                        <span>{evidence.label}</span>
                                    ) : (
                                        <a
                                            href={evidence.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline hover:text-rust"
                                        >
                                            {evidence.label}
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {canRespond ? (
                    <PortalDecisionForm
                        heading="Your decision"
                        options={decisionOptions}
                        defaultDecision="accepted"
                        respondUrl={respondUrl}
                        submitLabel="Record my response →"
                        clarificationValue="clarification_requested"
                        footnote="Acceptance is a signature: it is recorded immutably against this frozen record and never assumed."
                    />
                ) : (
                    <PortalResponseList responses={responses} />
                )}
            </PortalLayout>
        </>
    );
}
