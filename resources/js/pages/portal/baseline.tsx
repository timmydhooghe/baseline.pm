import { Head } from '@inertiajs/react';
import PortalDecisionForm from '@/components/portal-decision-form';
import type { PortalDecisionOption } from '@/components/portal-decision-form';
import PortalResponseList from '@/components/portal-response-list';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import type {
    BaselineDecision,
    BaselineResponseView,
    BaselineReviewPayload,
    BaselineStatus,
} from '@/types';

type Props = {
    submission: BaselineReviewPayload;
    baseline: {
        status: BaselineStatus;
        statusLabel: string;
        submittedAt: string | null;
        approvedAt: string | null;
    };
    stakeholder: { name: string };
    responses: BaselineResponseView[];
    superseded: boolean;
    canRespond: boolean;
    respondUrl: string;
};

const decisionOptions: PortalDecisionOption<BaselineDecision>[] = [
    {
        value: 'approved',
        title: 'Approve',
        description:
            'You commit the engagement to this scope, schedule and value — it becomes the baseline all work is measured against.',
    },
    {
        value: 'rejected',
        title: 'Reject',
        description:
            'The submission goes back to the delivery team; nothing is committed.',
    },
    {
        value: 'clarification_requested',
        title: 'Request clarification',
        description:
            'Send it back with a question — nothing is approved or rejected yet.',
    },
];

const commercialModelLabels: Record<string, string> = {
    fixed_price: 'Fixed price',
    time_and_materials: 'Time & materials',
};

/**
 * The customer's baseline review (FA-5 step 6, FA-27): the frozen submission
 * — scope items, milestones, assumptions and contract value, never cost or
 * margin — with approve / reject / clarify recorded immutably against it.
 */
export default function PortalBaseline({
    submission,
    baseline,
    stakeholder,
    responses,
    superseded,
    canRespond,
    respondUrl,
}: Props) {
    const details = submission.baseline;
    const itemsOf = (type: string) =>
        submission.items.filter((item) => item.type === type);
    const deliverables = itemsOf('deliverable');
    const milestones = itemsOf('milestone');
    const context = [
        { label: 'Assumptions', items: itemsOf('assumption') },
        { label: 'Exclusions', items: itemsOf('exclusion') },
        { label: 'Your responsibilities', items: itemsOf('responsibility') },
    ].filter((group) => group.items.length > 0);

    return (
        <>
            <Head
                title={`Baseline v${details.version} — ${details.engagement.name}`}
            />
            <PortalLayout
                eyebrow={`Baseline review · ${details.engagement.name} · ${details.customer.name}`}
                title={`Baseline v${details.version}`}
                intro={`Reviewing as ${stakeholder.name}. This submission is frozen — your response is recorded immutably against exactly what you see here, and approving commits both sides to it.`}
            >
                {superseded && (
                    <div
                        className="border-[1.5px] border-ochre px-4 py-3 font-plex-mono text-[12px] font-semibold text-ochre uppercase"
                        data-test="superseded-banner"
                    >
                        This baseline has been revised since this link was sent
                        — it stays here for your records, but the decision
                        happens on the latest version in your inbox.
                    </div>
                )}
                {baseline.status === 'approved' && (
                    <div className="border-[1.5px] border-moss px-4 py-3 font-plex-mono text-[12px] font-semibold text-moss uppercase">
                        Approved {baseline.approvedAt} — this is the committed
                        version the engagement runs against.
                    </div>
                )}
                {baseline.status === 'draft' && !superseded && (
                    <div className="border-[1.5px] border-stone px-4 py-3 font-plex-mono text-[12px] font-semibold text-stone uppercase">
                        This submission is back with the delivery team — a
                        revised version will follow.
                    </div>
                )}

                <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-3">
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Contract value</div>
                        <div
                            className="mt-1 font-plex-mono text-[22px] font-bold"
                            data-test="review-contract-value"
                        >
                            {details.contract_value.formatted}
                        </div>
                        <div className="text-[11px] text-stone">
                            {commercialModelLabels[details.commercial_model] ??
                                details.commercial_model}
                        </div>
                    </div>
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Timeline</div>
                        <div className="mt-1 text-[14px] font-semibold">
                            {details.start_date} → {details.end_date}
                        </div>
                    </div>
                    <div className="px-4 py-3">
                        <div className={portalSectionLabel}>Submitted</div>
                        <div className="mt-1 text-[14px] font-semibold">
                            {baseline.submittedAt ?? '—'}
                        </div>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink">
                    <div className="flex items-baseline justify-between border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>Deliverables</span>
                        <span className="font-plex-mono text-[12px] font-bold">
                            {deliverables.length}
                        </span>
                    </div>
                    {deliverables.length === 0 ? (
                        <p className="px-4 py-4 text-[13px] text-stone">
                            This baseline carries no deliverables.
                        </p>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {deliverables.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-col gap-2 px-4 py-3"
                                    data-test="review-deliverable"
                                >
                                    <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                        <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                            {item.title}
                                        </span>
                                        <span className="font-plex-mono text-[13px] font-semibold">
                                            {item.value?.formatted ?? '—'}
                                        </span>
                                    </div>
                                    {item.description !== null && (
                                        <p className="text-[13px]">
                                            {item.description}
                                        </p>
                                    )}
                                    <p className="text-[11px] text-stone">
                                        {item.clause_reference}
                                    </p>
                                    {(item.acceptance_criteria?.length ?? 0) >
                                        0 && (
                                        <ul className="flex flex-col gap-1 border-l-2 border-ink/20 pl-3">
                                            {item.acceptance_criteria?.map(
                                                (criterion, index) => (
                                                    <li
                                                        key={index}
                                                        className="text-[12px]"
                                                    >
                                                        {criterion.criterion}
                                                        {criterion.verification_method !==
                                                            null && (
                                                            <span className="text-stone">
                                                                {' '}
                                                                — verified by{' '}
                                                                {
                                                                    criterion.verification_method
                                                                }
                                                            </span>
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="border-[1.5px] border-ink">
                    <div className="flex items-baseline justify-between border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>Milestones</span>
                        <span className="font-plex-mono text-[12px] font-bold">
                            {milestones.length}
                        </span>
                    </div>
                    {milestones.length === 0 ? (
                        <p className="px-4 py-4 text-[13px] text-stone">
                            This baseline carries no milestones.
                        </p>
                    ) : (
                        <ul className="divide-y divide-ink/15">
                            {milestones.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
                                    data-test="review-milestone"
                                >
                                    <span className="min-w-0 flex-1 text-[14px] font-semibold">
                                        {item.title}
                                    </span>
                                    {item.payment_trigger !== null && (
                                        <span className="text-[12px] text-stone">
                                            {item.payment_trigger}
                                        </span>
                                    )}
                                    <span className="font-plex-mono text-[12px]">
                                        {item.baseline_date ?? '—'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {context.map((group) => (
                    <div
                        key={group.label}
                        className="border-[1.5px] border-ink"
                    >
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>
                                {group.label}
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15">
                            {group.items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-col gap-1 px-4 py-3"
                                >
                                    <span className="text-[14px] font-semibold">
                                        {item.title}
                                    </span>
                                    {item.description !== null && (
                                        <p className="text-[13px]">
                                            {item.description}
                                        </p>
                                    )}
                                    <p className="text-[11px] text-stone">
                                        {item.clause_reference}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}

                {submission.documents.length > 0 && (
                    <div className="border-[1.5px] border-ink">
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>
                                Contract documents on file
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15">
                            {submission.documents.map((document) => (
                                <li
                                    key={document.id}
                                    className="px-4 py-3 text-[13px]"
                                >
                                    {document.filename}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {canRespond ? (
                    <PortalDecisionForm
                        heading="Your decision"
                        options={decisionOptions}
                        defaultDecision="approved"
                        respondUrl={respondUrl}
                        submitLabel="Record my response →"
                        clarificationValue="clarification_requested"
                        footnote="Approving is signing: it is recorded immutably against this frozen submission, and every later change needs your approval again."
                    />
                ) : (
                    <PortalResponseList responses={responses} />
                )}
            </PortalLayout>
        </>
    );
}
