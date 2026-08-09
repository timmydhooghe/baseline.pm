import { Form, Head } from '@inertiajs/react';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import type { DecisionAcknowledgementPayload } from '@/types';

type Props = {
    record: DecisionAcknowledgementPayload;
    decision: {
        statusLabel: string;
        acknowledgedAt: string | null;
        acknowledgedByName: string | null;
        acknowledgementComment: string | null;
    };
    stakeholder: { name: string };
    superseded: boolean;
    canAcknowledge: boolean;
    acknowledgeUrl: string;
};

/**
 * The customer's view of a shared decision (FA-18). Acknowledgment is not
 * approval — it confirms the record was seen — but it is stored immutably
 * against exactly this frozen payload, which carries no cost or margin.
 */
export default function PortalDecision({
    record,
    decision,
    stakeholder,
    superseded,
    canAcknowledge,
    acknowledgeUrl,
}: Props) {
    const details = record.decision;

    return (
        <>
            <Head title={`Decision — ${details.title}`} />
            <PortalLayout
                eyebrow={`Decision · ${details.engagement.name} · ${details.customer.name}`}
                title={details.title}
                intro={`Shown to ${stakeholder.name}. This record is frozen — acknowledging confirms you have seen it, and is recorded against exactly what is on this page.`}
            >
                {superseded && (
                    <div
                        className="border-[1.5px] border-ochre px-4 py-3 font-plex-mono text-[12px] font-semibold text-ochre uppercase"
                        data-test="superseded-banner"
                    >
                        This decision has been revised since this link was sent
                        — it stays here for your records.
                    </div>
                )}

                {decision.acknowledgedAt !== null && (
                    <div
                        className="border-[1.5px] border-moss px-4 py-3 font-plex-mono text-[12px] font-semibold text-moss uppercase"
                        data-test="acknowledged-banner"
                    >
                        Acknowledged {decision.acknowledgedAt} by{' '}
                        {decision.acknowledgedByName}.
                    </div>
                )}

                <div className="border-[1.5px] border-ink">
                    <div className="border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>
                            What was decided
                        </span>
                    </div>
                    <div className="flex flex-col gap-3 px-4 py-4 text-[14px]">
                        <p className="whitespace-pre-line">
                            {details.decision ?? '—'}
                        </p>
                        <p className="text-[12px] text-stone">
                            Decided {details.decided_on ?? 'undated'}
                        </p>
                    </div>
                </div>

                <div className="border-[1.5px] border-ink">
                    <div className="border-b-[1.5px] border-ink px-4 py-3">
                        <span className={portalSectionLabel}>Context</span>
                    </div>
                    <p className="px-4 py-4 text-[14px] whitespace-pre-line">
                        {details.context}
                    </p>
                </div>

                {record.alternatives.length > 0 && (
                    <div className="border-[1.5px] border-ink">
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>
                                Alternatives considered
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/15">
                            {record.alternatives.map((alternative, index) => (
                                <li
                                    key={`${alternative.option}-${index}`}
                                    className="px-4 py-3 text-[13px]"
                                >
                                    <span className="font-medium">
                                        {alternative.option}
                                    </span>
                                    {alternative.why_not !== null && (
                                        <span className="block text-stone">
                                            {alternative.why_not}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-2">
                    <div className="border-ink/20 px-4 py-3 sm:border-r">
                        <div className={portalSectionLabel}>Scope impact</div>
                        <div className="mt-1 text-[14px]">
                            {record.impact.scope ?? '—'}
                        </div>
                    </div>
                    <div className="px-4 py-3">
                        <div className={portalSectionLabel}>
                            Timeline impact
                        </div>
                        <div className="mt-1 font-plex-mono text-[22px] font-bold">
                            {record.impact.timeline_days === null
                                ? '—'
                                : `${record.impact.timeline_days > 0 ? '+' : ''}${record.impact.timeline_days} days`}
                        </div>
                    </div>
                </div>

                {record.participants.length > 0 && (
                    <div className="border-[1.5px] border-ink">
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>
                                Who was involved
                            </span>
                        </div>
                        <ul className="flex flex-wrap gap-2 px-4 py-3 text-[13px]">
                            {record.participants.map((participant, index) => (
                                <li
                                    key={`${participant.name}-${index}`}
                                    className="border border-ink/40 px-2 py-0.5"
                                >
                                    {participant.name}
                                    {participant.affiliation !== null &&
                                        ` · ${participant.affiliation}`}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {record.evidence.length > 0 && (
                    <div className="border-[1.5px] border-ink">
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>Evidence</span>
                        </div>
                        <ul className="divide-y divide-ink/15">
                            {record.evidence.map((evidence, index) => (
                                <li
                                    key={`${evidence.label}-${index}`}
                                    className="px-4 py-3 text-[13px]"
                                >
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

                {canAcknowledge && (
                    <div className="border-[1.5px] border-ink">
                        <div className="border-b-[1.5px] border-ink px-4 py-3">
                            <span className={portalSectionLabel}>
                                Acknowledge this decision
                            </span>
                        </div>
                        <Form
                            action={acknowledgeUrl}
                            method="post"
                            className="flex flex-col gap-3 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <label
                                        htmlFor="comment"
                                        className="text-[13px] text-stone"
                                    >
                                        Anything you want on the record
                                        (optional)
                                    </label>
                                    <textarea
                                        id="comment"
                                        name="comment"
                                        rows={3}
                                        className="w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[14px] outline-none"
                                        data-test="acknowledge-comment"
                                    />
                                    {errors.acknowledgement !== undefined && (
                                        <p className="text-[13px] text-rust">
                                            {errors.acknowledgement}
                                        </p>
                                    )}
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit border-[1.5px] border-ink bg-ink px-4 py-2 font-plex-mono text-[12px] font-semibold text-paper uppercase disabled:opacity-50"
                                        data-test="acknowledge-decision"
                                    >
                                        I have seen this decision →
                                    </button>
                                    <p className="text-[12px] text-stone">
                                        Acknowledging is not approval — it
                                        records that this decision was shared
                                        with you and read.
                                    </p>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {decision.acknowledgementComment !== null && (
                    <div className="border-[1.5px] border-ink px-4 py-3 text-[13px]">
                        <span className={portalSectionLabel}>Your comment</span>
                        <p className="mt-1">
                            {decision.acknowledgementComment}
                        </p>
                    </div>
                )}
            </PortalLayout>
        </>
    );
}
