import { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';

type PortalResponse = {
    id: string;
    decision: string;
    decisionLabel: string;
    stakeholderName: string;
    comment: string | null;
    respondedAt: string;
};

const positiveDecisions = ['approved', 'accepted'];

/**
 * The decisions already on record for a portal review — immutable, so a
 * change of mind appears as a new entry rather than an edit.
 */
export default function PortalResponseList({
    responses,
    heading = 'Decisions on record',
}: {
    responses: PortalResponse[];
    heading?: string;
}) {
    if (responses.length === 0) {
        return null;
    }

    return (
        <div className="border-[1.5px] border-ink">
            <div className="border-b-[1.5px] border-ink px-4 py-3">
                <span className={portalSectionLabel}>{heading}</span>
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
                                    positiveDecisions.includes(
                                        response.decision,
                                    ) && 'border-moss text-moss',
                                    response.decision === 'rejected' &&
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
                            <p className="text-stone">“{response.comment}”</p>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
