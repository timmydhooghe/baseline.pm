import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { home as portalHome, logout as portalLogout } from '@/routes/portal';

export const portalSectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase';

export type PortalSession = {
    stakeholderName: string;
    customerName: string;
};

/**
 * The customer portal's page chrome (FA-27): a letterhead over shared
 * records. Signed-in stakeholders get the session band with their name and a
 * way out; review pages opened from a personally signed email link keep the
 * bare letterhead — no app navigation, no theme switching, and never a
 * figure the customer is not meant to see.
 */
export default function PortalLayout({
    eyebrow,
    title,
    intro,
    session,
    wide = false,
    children,
}: {
    eyebrow: string;
    title: string;
    intro?: ReactNode;
    session?: PortalSession;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="min-h-screen bg-paper font-sans text-ink">
            <div
                className={cn(
                    'mx-auto flex w-full flex-col gap-6 px-6 py-6 sm:py-10',
                    wide ? 'max-w-4xl' : 'max-w-2xl',
                )}
            >
                <header className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 border-b-[1.5px] border-ink pb-4">
                    <div className="flex items-baseline gap-3">
                        <Link
                            href={portalHome()}
                            className="font-display text-[20px] font-bold tracking-[-0.01em]"
                        >
                            Baseline<span className="text-rust">.</span>
                        </Link>
                        <span className="font-plex-mono text-[10px] font-semibold tracking-[0.14em] text-stone uppercase">
                            Client portal
                        </span>
                    </div>
                    {session !== undefined && (
                        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span
                                className="font-plex-mono text-[11px] text-stone"
                                data-test="portal-session"
                            >
                                {session.customerName} ·{' '}
                                {session.stakeholderName}
                            </span>
                            <Link
                                href={portalHome()}
                                className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase underline-offset-4 hover:text-rust hover:underline"
                            >
                                Overview
                            </Link>
                            <Link
                                href={portalLogout()}
                                method="post"
                                as="button"
                                className="cursor-pointer font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase underline-offset-4 hover:text-rust hover:underline"
                                data-test="portal-logout"
                            >
                                Sign out
                            </Link>
                        </div>
                    )}
                </header>
                <div>
                    <div className={portalSectionLabel}>{eyebrow}</div>
                    <h1 className="mt-1 font-display text-[26px] font-bold tracking-[-0.02em] text-balance">
                        {title}
                    </h1>
                    {intro !== undefined && (
                        <p className="mt-1 max-w-prose text-[13px] text-stone">
                            {intro}
                        </p>
                    )}
                </div>
                {children}
                <footer className="border-t border-ink/15 pt-4 font-plex-mono text-[10px] tracking-[0.08em] text-stone uppercase">
                    Shared records only — every decision you record here is
                    frozen exactly as shown.
                </footer>
            </div>
        </div>
    );
}
