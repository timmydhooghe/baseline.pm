import { Head } from '@inertiajs/react';

/**
 * Stakeholder portal landing. Stakeholders sign in via magic link / signed
 * URL on the separate `stakeholder` guard — the flow lands with WEBAPP-16.
 */
export default function PortalWelcome() {
    return (
        <>
            <Head title="Stakeholder portal" />
            <div className="flex min-h-screen items-center justify-center bg-paper p-6 font-sans text-ink">
                <div className="w-full max-w-md border-[1.5px] border-ink bg-paper p-8">
                    <div className="font-display text-[20px] font-bold tracking-[-0.01em]">
                        Baseline<span className="text-rust">.</span>
                    </div>
                    <div className="mt-6 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase">
                        Stakeholder portal
                    </div>
                    <p className="mt-2 text-[14px] leading-relaxed text-stone">
                        This is where you follow the engagements your team runs
                        with us — scope, change requests and progress, without
                        the noise.
                    </p>
                    <p className="mt-4 border-[1.5px] border-ink bg-sun/40 p-3 font-plex-mono text-[12px]">
                        Access is by invitation: your delivery team sends you a
                        personal sign-in link.
                    </p>
                </div>
            </div>
        </>
    );
}
