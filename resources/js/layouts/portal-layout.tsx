import type { ReactNode } from 'react';

export const portalSectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase';

/**
 * The customer portal's page chrome (FA-27). Stakeholders arrive on
 * personally signed links without an account, so the portal keeps its own
 * minimal shell — no app navigation, no theme switching, and never a figure
 * the customer is not meant to see.
 */
export default function PortalLayout({
    eyebrow,
    title,
    intro,
    children,
}: {
    eyebrow: string;
    title: string;
    intro?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="min-h-screen bg-paper p-6 font-sans text-ink">
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <div>
                    <div className="font-display text-[20px] font-bold tracking-[-0.01em]">
                        Baseline<span className="text-rust">.</span>
                    </div>
                    <div className={`mt-4 ${portalSectionLabel}`}>
                        {eyebrow}
                    </div>
                    <h1 className="mt-1 font-display text-[26px] font-bold tracking-[-0.02em]">
                        {title}
                    </h1>
                    {intro !== undefined && (
                        <p className="mt-1 text-[13px] text-stone">{intro}</p>
                    )}
                </div>
                {children}
            </div>
        </div>
    );
}
