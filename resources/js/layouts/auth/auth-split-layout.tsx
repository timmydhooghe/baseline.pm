import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
    footer,
}: AuthLayoutProps) {
    return (
        // The auth pages are fixed-light per the brand direction, so the shadcn
        // semantic tokens are pinned to their light values regardless of `.dark`.
        <div className="flex min-h-svh bg-paper font-onest text-soot [--color-background:#ffffff] [--color-border:#141414] [--color-destructive:#b3372b] [--color-foreground:#141414] [--color-input:#141414] [--color-muted-foreground:#6f6a60] [--color-primary-foreground:#f3f1ec] [--color-primary:#141414] [--color-ring:#141414] selection:bg-sun">
            <div className="flex flex-1 items-center justify-center px-10 py-12">
                <div className="w-full max-w-[400px]">
                    <Link
                        href={home()}
                        className="inline-block animate-rise-in font-display text-[20px] font-bold tracking-[-.01em] text-ink"
                    >
                        BASELINE
                    </Link>

                    <div className="mt-5.5 animate-rise-in border-2 border-ink bg-white px-7.5 py-7 shadow-[8px_8px_0_var(--color-ink)] [animation-delay:.08s]">
                        <h1 className="font-display text-2xl font-bold tracking-[-.02em] text-ink">
                            {title}
                        </h1>
                        {description && (
                            <p className="mt-1 text-[12.5px] text-stone">
                                {description}
                            </p>
                        )}
                        <div className="mt-5">{children}</div>
                    </div>

                    {footer && (
                        <div className="mt-4 animate-rise-in text-center text-[12.5px] text-stone [animation-delay:.16s]">
                            {footer}
                        </div>
                    )}
                </div>
            </div>

            <div className="hidden w-[44%] flex-none flex-col justify-center bg-ink px-15 py-16 text-paper lg:flex">
                <div className="font-plex-mono text-[11px] tracking-[.08em] text-ash">
                    THE POSITION — LIVE
                </div>
                <div className="mt-3.5 font-display text-[34px] leading-[1.15] font-bold tracking-[-.02em]">
                    Every euro of scope,
                    <br />
                    on the record.
                </div>

                <div className="mt-8.5 grid max-w-[360px] gap-3 font-plex-mono text-[11px]">
                    <div>
                        <div className="flex justify-between">
                            <span>APPROVED</span>
                            <b>€249,000</b>
                        </div>
                        <div className="mt-1 h-4 origin-left animate-grow-bar bg-paper [animation-delay:.3s]" />
                    </div>
                    <div className="text-[#7fbf9b]">
                        <div className="flex justify-between">
                            <span>ACCEPTED</span>
                            <b>€76,100</b>
                        </div>
                        <div className="mt-1 h-4 w-[31%] origin-left animate-grow-bar bg-moss [animation-delay:.45s]" />
                    </div>
                    <div className="text-[#e0c98a]">
                        <div className="flex justify-between">
                            <span>PENDING CR</span>
                            <b>€18,400</b>
                        </div>
                        <div className="mt-1 h-4 w-[9%] origin-left animate-grow-bar border border-ochre bg-[repeating-linear-gradient(45deg,var(--color-ochre),var(--color-ochre)_4px,var(--color-ink)_4px,var(--color-ink)_8px)] [animation-delay:.6s]" />
                    </div>
                    <div className="text-[#e08573]">
                        <div className="flex justify-between">
                            <span>UNBILLED RISK</span>
                            <b>€9,200</b>
                        </div>
                        <div className="mt-1 h-4 w-[5%] origin-left animate-grow-bar border-2 border-dashed border-rust [animation-delay:.75s]" />
                    </div>
                </div>

                <div className="mt-8.5 max-w-[380px] border-t border-soot pt-4.5 text-[13.5px] leading-[1.65] text-pretty text-fog">
                    &ldquo;Scope creep never announces itself. Baseline caught{' '}
                    <b className="bg-sun px-1 text-ink">€9,200</b> of it in our
                    first sprint.&rdquo;
                    <div className="mt-2 font-plex-mono text-[10.5px] text-ash">
                        DELIVERY DIRECTOR · 40-PERSON AGENCY · GHENT
                    </div>
                </div>
            </div>
        </div>
    );
}
