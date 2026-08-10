import { Head, Link } from '@inertiajs/react';
import PortalLayout, { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import { show as engagementShow } from '@/routes/portal/engagements';
import type { PortalEngagementCard, PortalStakeholderView } from '@/types';

type Props = {
    stakeholder: PortalStakeholderView;
    customer: { name: string };
    organization: { name: string };
    engagements: PortalEngagementCard[];
};

/**
 * The signed-in stakeholder's overview (FA-27): every engagement their
 * customer can see, with what awaits them on each. A customer with a single
 * engagement never lands here — the server sends them straight to it.
 */
export default function PortalHome({
    stakeholder,
    customer,
    organization,
    engagements,
}: Props) {
    return (
        <>
            <Head title="Client portal" />
            <PortalLayout
                eyebrow="Client portal"
                title={`Engagements with ${organization.name}`}
                intro={`Signed in as ${stakeholder.name} (${stakeholder.roleLabel}) for ${customer.name}.`}
                session={{
                    stakeholderName: stakeholder.name,
                    customerName: customer.name,
                }}
                wide
            >
                {engagements.length === 0 ? (
                    <div className="border-[1.5px] border-ink px-4 py-8 text-center text-[13px] text-stone">
                        Nothing has been shared with you yet. As soon as an
                        engagement reaches its first baseline, it appears here.
                    </div>
                ) : (
                    <ul className="flex flex-col gap-4">
                        {engagements.map((engagement) => (
                            <li key={engagement.id}>
                                <Link
                                    href={engagementShow(engagement.id)}
                                    className="group block border-[1.5px] border-ink transition-colors hover:bg-bone"
                                    data-test="portal-engagement-card"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3">
                                        <span className="font-display text-[18px] font-bold tracking-[-0.01em] group-hover:text-rust">
                                            {engagement.name}
                                        </span>
                                        <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase">
                                            {engagement.statusLabel}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2 px-4 py-3">
                                        <span className="font-plex-mono text-[12px] text-stone">
                                            {engagement.baselineVersion === null
                                                ? 'Baseline awaiting approval'
                                                : `Baseline v${engagement.baselineVersion}`}
                                        </span>
                                        <span
                                            className={cn(
                                                'font-plex-mono text-[12px]',
                                                engagement.awaitingCount > 0
                                                    ? 'bg-sun/60 px-1.5 py-0.5 font-semibold'
                                                    : 'text-stone',
                                            )}
                                            data-test="portal-awaiting-count"
                                        >
                                            {engagement.awaitingCount === 0
                                                ? 'Nothing awaits your decision'
                                                : `${engagement.awaitingCount} awaiting your decision`}
                                        </span>
                                        <span
                                            className={cn(
                                                'font-plex-mono text-[12px]',
                                                engagement.owedCount > 0
                                                    ? 'font-semibold text-ochre'
                                                    : 'text-stone',
                                            )}
                                        >
                                            {engagement.owedCount === 0
                                                ? 'Nothing owed by your team'
                                                : `${engagement.owedCount} owed by your team`}
                                        </span>
                                        {engagement.lastReport !== null && (
                                            <span className="font-plex-mono text-[12px] text-stone">
                                                Last report{' '}
                                                {engagement.lastReport}
                                            </span>
                                        )}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <p className={portalSectionLabel}>
                    {engagements.length === 1
                        ? '1 engagement shared with you'
                        : `${engagements.length} engagements shared with you`}
                </p>
            </PortalLayout>
        </>
    );
}
