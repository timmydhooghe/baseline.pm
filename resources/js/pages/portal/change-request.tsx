import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase';

const decisionOptions: {
    value: ChangeRequestDecision;
    title: string;
    description: string;
}[] = [
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
    const [decision, setDecision] = useState<ChangeRequestDecision>('approved');

    const details = proposal.change_request;

    return (
        <>
            <Head title={`Change request — ${details.title}`} />
            <div className="min-h-screen bg-paper p-6 font-sans text-ink">
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                    <div>
                        <div className="font-display text-[20px] font-bold tracking-[-0.01em]">
                            Baseline<span className="text-rust">.</span>
                        </div>
                        <div className="mt-4 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase">
                            Change request · {details.engagement.name} ·{' '}
                            {details.customer.name}
                        </div>
                        <h1 className="mt-1 font-display text-[26px] font-bold tracking-[-0.02em]">
                            {details.title}
                        </h1>
                        <p className="mt-1 text-[13px] text-stone">
                            Reviewing as {stakeholder.name}. This proposal is
                            frozen — your response is recorded immutably against
                            exactly what you see here.
                        </p>
                    </div>

                    {changeRequest.status === 'approved' && (
                        <div className="border-[1.5px] border-moss px-4 py-3 font-plex-mono text-[12px] font-semibold text-moss uppercase">
                            Approved {changeRequest.decidedAt} — the change is
                            part of the agreed baseline.
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
                            <span className={sectionLabel}>The change</span>
                        </div>
                        <div className="flex flex-col gap-3 px-4 py-4 text-[14px]">
                            <p>{details.what}</p>
                            {details.why !== null && (
                                <p className="text-stone">{details.why}</p>
                            )}
                            {proposal.scope.added !== null && (
                                <div>
                                    <span className={sectionLabel}>Added</span>
                                    <p className="mt-1">
                                        {proposal.scope.added}
                                    </p>
                                </div>
                            )}
                            {proposal.scope.removed !== null && (
                                <div>
                                    <span className={sectionLabel}>
                                        Removed
                                    </span>
                                    <p className="mt-1">
                                        {proposal.scope.removed}
                                    </p>
                                </div>
                            )}
                            {proposal.scope.alternatives !== null && (
                                <div>
                                    <span className={sectionLabel}>
                                        Alternatives considered
                                    </span>
                                    <p className="mt-1">
                                        {proposal.scope.alternatives}
                                    </p>
                                </div>
                            )}
                            {proposal.affected_items.length > 0 && (
                                <div>
                                    <span className={sectionLabel}>
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
                            <div className={sectionLabel}>Price</div>
                            <div
                                className="mt-1 font-plex-mono text-[22px] font-bold"
                                data-test="proposal-price"
                            >
                                {proposal.price?.formatted ?? '—'}
                            </div>
                        </div>
                        <div className="px-4 py-3">
                            <div className={sectionLabel}>Schedule</div>
                            {proposal.schedule_impact === null ? (
                                <div className="mt-1 text-[14px] text-stone">
                                    No milestone moves.
                                </div>
                            ) : (
                                <div className="mt-1 text-[14px]">
                                    <span className="font-semibold">
                                        {
                                            proposal.schedule_impact.milestone
                                                .title
                                        }
                                    </span>{' '}
                                    moves{' '}
                                    <span className="font-plex-mono font-semibold">
                                        {(proposal.schedule_impact.days ?? 0) >=
                                        0
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
                        <div className="border-[1.5px] border-ink">
                            <div className="border-b-[1.5px] border-ink px-4 py-3">
                                <span className={sectionLabel}>
                                    Your decision
                                </span>
                            </div>
                            <Form
                                action={respondUrl}
                                method="post"
                                className="flex flex-col gap-4 px-4 py-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="flex flex-col gap-2">
                                            {decisionOptions.map((option) => (
                                                <label
                                                    key={option.value}
                                                    className={cn(
                                                        'flex cursor-pointer items-start gap-3 border-[1.5px] px-3 py-2',
                                                        decision ===
                                                            option.value
                                                            ? 'border-rust'
                                                            : 'border-ink/40 hover:border-ink',
                                                    )}
                                                    data-test={`decision-${option.value}`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="decision"
                                                        value={option.value}
                                                        checked={
                                                            decision ===
                                                            option.value
                                                        }
                                                        onChange={() =>
                                                            setDecision(
                                                                option.value,
                                                            )
                                                        }
                                                        className="mt-1 accent-rust"
                                                    />
                                                    <span className="flex flex-col">
                                                        <span className="text-[14px] font-semibold">
                                                            {option.title}
                                                        </span>
                                                        <span className="text-[12px] text-stone">
                                                            {option.description}
                                                        </span>
                                                    </span>
                                                </label>
                                            ))}
                                            <InputError
                                                message={errors.decision}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <label
                                                htmlFor="response-comment"
                                                className={sectionLabel}
                                            >
                                                Comment (optional)
                                            </label>
                                            <textarea
                                                id="response-comment"
                                                name="comment"
                                                rows={3}
                                                placeholder={
                                                    decision ===
                                                    'clarification_requested'
                                                        ? 'What should the delivery team clarify?'
                                                        : 'Anything you want on the record with this decision.'
                                                }
                                                className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] outline-none"
                                                data-test="response-comment"
                                            />
                                            <InputError
                                                message={errors.comment}
                                            />
                                        </div>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust"
                                                data-test="record-response"
                                            >
                                                Record my response →
                                            </Button>
                                        </div>
                                        <p className="text-[12px] text-stone">
                                            Responses are immutable: a change of
                                            mind is a new record, never an edit.
                                        </p>
                                    </>
                                )}
                            </Form>
                        </div>
                    ) : (
                        responses.length > 0 && (
                            <div className="border-[1.5px] border-ink">
                                <div className="border-b-[1.5px] border-ink px-4 py-3">
                                    <span className={sectionLabel}>
                                        Decisions on record
                                    </span>
                                </div>
                                <ul className="divide-y divide-ink/15">
                                    {responses.map((response) => (
                                        <li
                                            key={response.id}
                                            className="flex flex-col gap-1 px-4 py-3 text-[13px]"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span
                                                    className={cn(
                                                        'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase',
                                                        response.decision ===
                                                            'approved' &&
                                                            'border-moss text-moss',
                                                        response.decision ===
                                                            'rejected' &&
                                                            'border-rust text-rust',
                                                        response.decision ===
                                                            'clarification_requested' &&
                                                            'border-ochre text-ochre',
                                                    )}
                                                >
                                                    {response.decisionLabel}
                                                </span>
                                                <span className="font-medium">
                                                    {response.stakeholderName}
                                                </span>
                                                <span className="text-stone">
                                                    {response.respondedAt}
                                                </span>
                                            </div>
                                            {response.comment !== null && (
                                                <p className="text-stone">
                                                    “{response.comment}”
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )
                    )}
                </div>
            </div>
        </>
    );
}
