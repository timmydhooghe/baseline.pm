import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';

const btn =
    'inline-block cursor-pointer rounded-[3px] text-center font-bold transition-all duration-[180ms]';
const btnDark = `${btn} bg-ink text-paper hover:bg-rust`;
const btnOutline = `${btn} border-[1.5px] border-ink`;
const ctaSize = 'px-6.5 py-3.5 text-[14px]';
const lift =
    'transition-[translate,box-shadow] duration-200 hover:-translate-x-[3px] hover:-translate-y-[3px] hover:shadow-[6px_6px_0_var(--color-ink)]';
const kicker = 'font-plex-mono text-[12px] font-semibold';
const sectionTitle =
    'mt-2.5 font-display text-[38px] font-bold tracking-[-.02em]';
const cardLabel = 'font-plex-mono text-[11px] font-semibold text-stone';
const cardTitle = 'mt-2 font-display text-[19px] font-bold';
const highlight = 'bg-sun px-[5px]';

/**
 * Reveals its children with a rise-in transition once scrolled into view,
 * mirroring the design's IntersectionObserver behavior: elements already in
 * the initial viewport render as-is, everything below animates in on scroll.
 */
function Reveal({
    delay = 0,
    className,
    children,
}: {
    delay?: number;
    className?: string;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [reveal, setReveal] = useState<'initial' | 'hidden' | 'visible'>(
        'initial',
    );

    useLayoutEffect(() => {
        const element = ref.current;

        if (
            !element ||
            element.getBoundingClientRect().top < window.innerHeight * 0.92
        ) {
            return;
        }

        setReveal('hidden');
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setReveal('visible');
                    observer.disconnect();
                }
            },
            { rootMargin: '0px 0px -8% 0px' },
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            className={cn(
                className,
                reveal !== 'initial' &&
                    'transition-[opacity,translate] duration-[650ms] ease-[cubic-bezier(.2,.7,.3,1)]',
                reveal === 'hidden' && 'translate-y-6 opacity-0',
            )}
            style={
                reveal === 'visible'
                    ? { transitionDelay: `${delay}s` }
                    : undefined
            }
        >
            {children}
        </div>
    );
}

function TourModal({
    closing,
    onClose,
}: {
    closing: boolean;
    onClose: () => void;
}) {
    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Baseline 2-minute tour"
            onClick={onClose}
            className={cn(
                'fixed inset-0 z-[100] flex items-center justify-center bg-ink/82 p-10',
                closing ? 'animate-overlay-out' : 'animate-overlay-in',
            )}
        >
            <div
                onClick={(event) => event.stopPropagation()}
                className={cn(
                    'relative w-[min(920px,100%)] border-2 border-ink bg-ink shadow-[12px_12px_0_var(--color-rust)]',
                    closing ? 'animate-modal-out' : 'animate-modal-in',
                )}
            >
                <div className="flex items-center gap-2.5 border-b-2 border-ink bg-paper px-4 py-3">
                    <span className="font-plex-mono text-[11.5px] font-semibold text-ink">
                        BASELINE — 2-MIN TOUR
                    </span>
                    <span className="font-plex-mono text-[10.5px] text-stone">
                        placeholder footage — final cut in production
                    </span>
                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-auto cursor-pointer border-[1.5px] border-ink px-2 py-0.5 font-plex-mono text-[12px] font-bold text-ink transition-colors duration-[180ms] hover:border-rust hover:text-rust"
                    >
                        CLOSE ×
                    </button>
                </div>
                <div className="aspect-video bg-black">
                    <iframe
                        src="https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1"
                        className="block h-full w-full border-0"
                        allow="autoplay; encrypted-media; fullscreen"
                        title="Baseline product tour"
                    />
                </div>
            </div>
        </div>
    );
}

function PositionCard() {
    return (
        <div className="w-[340px] flex-none animate-rise-in border-2 border-ink bg-white px-6.5 py-6 shadow-[8px_8px_0_var(--color-ink)] [animation-delay:.25s] [animation-duration:.7s]">
            <div className="flex items-baseline">
                <div className="text-[11px] font-bold tracking-[.08em] text-stone">
                    THE POSITION — LIVE
                </div>
                <div className="ml-auto font-plex-mono text-[10px] text-moss">
                    <span className="animate-pulse-dot">●</span> SYNCED 4 MIN
                    AGO
                </div>
            </div>
            <div className="mt-3.5 grid gap-2.5 font-plex-mono">
                <div>
                    <div className="flex justify-between text-[11.5px]">
                        <span>APPROVED</span>
                        <b>249.0k</b>
                    </div>
                    <div className="mt-[3px] h-[26px] origin-left animate-grow-bar bg-ink [animation-delay:.55s]" />
                </div>
                <div>
                    <div className="flex justify-between text-[11.5px]">
                        <span>ACCEPTED</span>
                        <b>76.1k</b>
                    </div>
                    <div className="mt-[3px] h-[26px] w-[31%] origin-left animate-grow-bar bg-moss [animation-delay:.7s]" />
                </div>
                <div>
                    <div className="flex justify-between text-[11.5px]">
                        <span>PENDING CR</span>
                        <b>18.4k</b>
                    </div>
                    <div className="mt-[3px] h-[26px] w-[7.5%] origin-left animate-grow-bar border border-ochre bg-[repeating-linear-gradient(45deg,var(--color-ochre),var(--color-ochre)_4px,#fff_4px,#fff_8px)] [animation-delay:.85s]" />
                </div>
                <div>
                    <div className="flex justify-between text-[11.5px] text-rust">
                        <span>UNBILLED RISK</span>
                        <b>9.2k</b>
                    </div>
                    <div className="mt-[3px] h-[26px] w-[4%] origin-left animate-grow-bar border-2 border-dashed border-rust [animation-delay:1s]" />
                </div>
            </div>
            <div className="mt-3.5 border-t border-sand-300 pt-3 text-[12.5px]">
                Baseline's job: keep the red block at{' '}
                <b className="bg-sun px-1">zero</b>.
            </div>
        </div>
    );
}

const navLinks = [
    { href: '#how', label: 'PRODUCT' },
    { href: '#position', label: 'THE POSITION' },
    { href: '#pricing', label: 'PRICING' },
    { href: '#manifesto', label: 'MANIFESTO' },
];

const bannerStatements = [
    'MORE PROFIT',
    'FASTER APPROVALS',
    'LESS DISCUSSIONS',
];

const problems = [
    {
        stat: '€9,200 gone',
        statColor: 'text-rust',
        title: 'Scope creep is invisible until invoice time',
        body: '"Small favours" land in the sprint without a contract line. Nobody decided to do them for free — nobody decided at all.',
        cellClass: 'border-r border-sand-400 py-6 pr-7.5',
        delay: 0,
    },
    {
        stat: '4 days, no answer',
        statColor: 'text-ochre',
        title: 'Change requests die in email threads',
        body: 'The approval sits unanswered — but the work starts anyway. When the invoice lands, the client remembers a conversation, not an agreement.',
        cellClass: 'border-r border-sand-400 px-7.5 py-6',
        delay: 0.08,
    },
    {
        stat: '11 days late',
        statColor: 'text-rust',
        title: 'Client delays become your penalty',
        body: 'Their late test data, your late milestone. Without day-by-day attribution, you eat the slip — or fight about it.',
        cellClass: 'py-6 pl-7.5',
        delay: 0.16,
    },
];

export default function Welcome() {
    const { auth } = usePage().props;
    const [tour, setTour] = useState<'closed' | 'open' | 'closing'>('closed');

    const closeTour = () =>
        setTour((current) => (current === 'open' ? 'closing' : current));

    useEffect(() => {
        if (tour !== 'closing') {
            return;
        }

        const timer = setTimeout(() => setTour('closed'), 210);

        return () => clearTimeout(timer);
    }, [tour]);

    useEffect(() => {
        if (tour !== 'open') {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setTour((current) =>
                    current === 'open' ? 'closing' : current,
                );
            }
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [tour]);

    return (
        <>
            <Head title="Fixed price. Not fixed losses.">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    rel="stylesheet"
                    href="https://fonts.bunny.net/css?family=ibm-plex-mono:400,500,600|onest:400,600,700|space-grotesk:700&display=swap"
                />
                <meta
                    name="description"
                    content="Baseline shows agencies their commercial position on fixed-price work every morning — scope creep priced, change requests signed, delays attributed."
                />
            </Head>
            <div className="min-h-screen min-w-[1100px] bg-paper font-onest text-ink selection:bg-sun">
                <header className="sticky top-0 z-10 flex items-center gap-[26px] border-b-2 border-ink bg-paper px-14 py-5">
                    <div className="font-display text-[18px] font-bold tracking-[-.01em]">
                        BASELINE<span className="text-rust">.</span>
                    </div>
                    {navLinks.map((link) => (
                        <a
                            key={link.label}
                            href={link.href}
                            className="text-[12.5px] font-semibold text-stone transition-colors duration-150 hover:text-rust"
                        >
                            {link.label}
                        </a>
                    ))}
                    {auth.user ? (
                        <Link
                            href={dashboard()}
                            className={cn(
                                btnDark,
                                'ml-auto px-[17px] py-[9px] text-[12.5px]',
                            )}
                        >
                            DASHBOARD →
                        </Link>
                    ) : (
                        <>
                            <Link
                                href={login()}
                                className={cn(
                                    btnOutline,
                                    'ml-auto px-4 py-2 text-[12.5px] hover:bg-ink hover:text-paper',
                                )}
                            >
                                SIGN IN
                            </Link>
                            <Link
                                href={login()}
                                className={cn(
                                    btnDark,
                                    'px-[17px] py-[9px] text-[12.5px]',
                                )}
                            >
                                GET BASELINE →
                            </Link>
                        </>
                    )}
                </header>

                <section className="mx-auto flex max-w-[1200px] items-center gap-14 px-14 pt-19 pb-16">
                    <div className="flex-[1.4]">
                        <h1 className="animate-rise-in font-display text-[64px] leading-none font-bold tracking-[-.03em]">
                            Fixed price.
                            <br />
                            Not fixed{' '}
                            <span className="bg-sun px-2">losses</span>.
                        </h1>
                        <p className="mt-5.5 max-w-[460px] animate-rise-in text-[16.5px] leading-[1.65] text-pretty text-stone [animation-delay:.1s]">
                            Agencies lose 4–9 margin points per fixed-price
                            project to unlogged scope, unsigned changes and
                            unattributed delays. Baseline shows your commercial
                            position every morning — and the three moves that
                            protect it.
                        </p>
                        <div className="mt-7.5 flex animate-rise-in gap-3 [animation-delay:.18s]">
                            <Link
                                href={login()}
                                className={cn(btnDark, ctaSize)}
                            >
                                SEE YOUR POSITION →
                            </Link>
                            <button
                                type="button"
                                onClick={() => setTour('open')}
                                className={cn(
                                    btnOutline,
                                    ctaSize,
                                    'hover:bg-white',
                                )}
                            >
                                2-MIN TOUR
                            </button>
                        </div>
                    </div>
                    <PositionCard />
                </section>

                {tour !== 'closed' && (
                    <TourModal
                        closing={tour === 'closing'}
                        onClose={closeTour}
                    />
                )}

                <div className="grid grid-cols-3 overflow-hidden border-t-2 border-ink bg-ink px-14 py-4.5 text-paper">
                    {bannerStatements.map((statement) => (
                        <span
                            key={statement}
                            className="flex items-center justify-center gap-3 border-l border-soot font-display text-[16px] font-bold tracking-[-.01em] first:border-l-0"
                        >
                            <span
                                aria-hidden="true"
                                className="size-2.5 shrink-0 rounded-full bg-moss"
                            />
                            <span>{statement}</span>
                        </span>
                    ))}
                </div>

                <section
                    id="position"
                    className="mx-auto max-w-[1200px] px-14 pt-19 pb-7.5"
                >
                    <div className={cn(kicker, 'text-rust')}>
                        01 — THE PROBLEM
                    </div>
                    <Reveal
                        className={cn(
                            sectionTitle,
                            'max-w-[700px] leading-[1.1]',
                        )}
                    >
                        Your Jira knows what's happening.
                        <br />
                        Your contract doesn't.
                    </Reveal>
                    <div className="mt-9 grid grid-cols-3 border-t-2 border-ink">
                        {problems.map((problem) => (
                            <Reveal
                                key={problem.title}
                                delay={problem.delay}
                                className={problem.cellClass}
                            >
                                <div
                                    className={cn(
                                        'font-plex-mono text-[26px] font-semibold',
                                        problem.statColor,
                                    )}
                                >
                                    {problem.stat}
                                </div>
                                <div className="mt-2 text-[15px] font-bold">
                                    {problem.title}
                                </div>
                                <div className="mt-1.5 text-[13px] leading-[1.6] text-pretty text-stone">
                                    {problem.body}
                                </div>
                            </Reveal>
                        ))}
                    </div>
                </section>

                <section
                    id="how"
                    className="mx-auto max-w-[1200px] px-14 py-15"
                >
                    <div className={cn(kicker, 'text-moss')}>
                        02 — THE MOVES
                    </div>
                    <Reveal className={cn(sectionTitle, 'leading-[1.1]')}>
                        Three moves, every morning.
                    </Reveal>
                    <div className="mt-9 grid gap-[22px]">
                        <Reveal>
                            <div
                                className={cn(
                                    'flex items-stretch gap-9 border-2 border-ink bg-white',
                                    lift,
                                )}
                            >
                                <div className="flex-1 py-7 pl-8">
                                    <div className="font-display text-[20px] font-bold">
                                        <span className={highlight}>
                                            CATCH SCOPE CREEP
                                        </span>{' '}
                                        before it's free work
                                    </div>
                                    <div className="mt-2.5 max-w-[400px] text-[13.5px] leading-[1.65] text-pretty text-stone">
                                        Every Jira and Linear issue is matched
                                        against your approved scope. Anything
                                        unlinked shows up priced in euros — not
                                        story points — with four ways out:
                                        in-scope, change request, operational,
                                        dismiss. Each classification is on the
                                        record.
                                    </div>
                                    <div className="mt-3 font-plex-mono text-[11px] text-stone">
                                        WORKS WITH JIRA · LINEAR · STANDALONE
                                    </div>
                                </div>
                                <div className="grid w-[360px] flex-none content-center gap-1.5 border-l-2 border-ink bg-paper p-5">
                                    <div className="flex items-baseline gap-2.5 border-[1.5px] border-ink bg-white px-3.5 py-2.5 text-[12px]">
                                        <span className="font-plex-mono font-semibold text-rust">
                                            CREEP
                                        </span>
                                        <b>MER-214 Excel export</b>
                                        <span className="ml-auto font-plex-mono">
                                            €4,700
                                        </span>
                                    </div>
                                    <div className="flex items-baseline gap-2.5 border-[1.5px] border-ink bg-white px-3.5 py-2.5 text-[12px]">
                                        <span className="font-plex-mono font-semibold text-rust">
                                            CREEP
                                        </span>
                                        <b>MER-219 depot filter</b>
                                        <span className="ml-auto font-plex-mono">
                                            €2,000
                                        </span>
                                    </div>
                                    <div className="mt-1 flex gap-1.5 text-[10.5px] font-bold">
                                        <span className="border-[1.5px] border-ink bg-white px-2 py-[3px]">
                                            IN-SCOPE
                                        </span>
                                        <span className="bg-ink px-2 py-[3px] text-white">
                                            CHANGE →
                                        </span>
                                        <span className="border-[1.5px] border-ink bg-white px-2 py-[3px]">
                                            OPS
                                        </span>
                                        <span className="border-[1.5px] border-ink bg-white px-2 py-[3px]">
                                            DISMISS
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                        <Reveal>
                            <div
                                className={cn(
                                    'flex items-stretch gap-9 border-2 border-ink bg-white',
                                    lift,
                                )}
                            >
                                <div className="grid w-[360px] flex-none content-center gap-2 border-r-2 border-ink bg-paper p-5">
                                    <div className="border-[1.5px] border-ink bg-white px-3.5 py-3 text-[12px]">
                                        <div className="flex items-baseline gap-2">
                                            <b>CR-07 Multi-depot</b>
                                            <span className="ml-auto font-plex-mono">
                                                +€18,400
                                            </span>
                                        </div>
                                        <div className="mt-[3px] text-[11px] text-stone">
                                            respond by Wed 5 Aug · trade-offs:
                                            swap / defer
                                        </div>
                                        <div className="mt-2 flex gap-1.5 text-[10.5px] font-bold">
                                            <span className="bg-moss px-2.5 py-1 text-white">
                                                APPROVE
                                            </span>
                                            <span className="border-[1.5px] border-rust px-2.5 py-1 text-rust">
                                                REJECT
                                            </span>
                                            <span className="border-[1.5px] border-ink px-2.5 py-1">
                                                CLARIFY
                                            </span>
                                        </div>
                                    </div>
                                    <div className="font-plex-mono text-[10.5px] text-moss">
                                        ✓ RESPONSE STORED IMMUTABLY · BASELINE
                                        v4 CREATED
                                    </div>
                                </div>
                                <div className="flex-1 py-7 pr-8">
                                    <div className="font-display text-[20px] font-bold">
                                        <span className={highlight}>
                                            GET CHANGES SIGNED
                                        </span>{' '}
                                        before work starts
                                    </div>
                                    <div className="mt-2.5 max-w-[400px] text-[13.5px] leading-[1.65] text-pretty text-stone">
                                        Change orders carry price, timeline
                                        impact, trade-off alternatives and a
                                        response deadline. Your client approves
                                        in their own portal — one click,
                                        identity verified, immutable. Approval
                                        creates a new baseline version
                                        automatically.
                                    </div>
                                    <div className="mt-3 font-plex-mono text-[11px] text-stone">
                                        NO WORK BEFORE APPROVAL — BREACHES GET
                                        FLAGGED
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                        <Reveal>
                            <div
                                className={cn(
                                    'flex items-stretch gap-9 border-2 border-ink bg-white',
                                    lift,
                                )}
                            >
                                <div className="flex-1 py-7 pl-8">
                                    <div className="font-display text-[20px] font-bold">
                                        <span className={highlight}>
                                            PROVE DELAYS
                                        </span>{' '}
                                        while they happen
                                    </div>
                                    <div className="mt-2.5 max-w-[400px] text-[13.5px] leading-[1.65] text-pretty text-stone">
                                        Client-owed dependencies have dates and
                                        owners. When one slips, the milestone
                                        impact is computed day-for-day and the
                                        attribution is recorded — acknowledged
                                        by the client at the time, not argued
                                        about at the end.
                                    </div>
                                    <div className="mt-3 font-plex-mono text-[11px] text-stone">
                                        EVERY REPORT LINKS TO ITS EVIDENCE
                                    </div>
                                </div>
                                <div className="grid w-[360px] flex-none content-center border-l-2 border-ink bg-paper p-5 font-plex-mono text-[11.5px]">
                                    <div className="flex border-b border-sand-400 py-[7px]">
                                        <span>DEP-3 test data</span>
                                        <span className="ml-auto text-rust">
                                            11d LATE
                                        </span>
                                    </div>
                                    <div className="flex border-b border-sand-400 py-[7px]">
                                        <span>→ M3 forecast</span>
                                        <span className="ml-auto">
                                            12 SEP → 26 SEP
                                        </span>
                                    </div>
                                    <div className="flex border-b border-sand-400 py-[7px]">
                                        <span>→ delay owner</span>
                                        <span className="ml-auto font-semibold">
                                            CUSTOMER
                                        </span>
                                    </div>
                                    <div className="flex py-[7px]">
                                        <span>→ recorded</span>
                                        <span className="ml-auto text-moss">
                                            D-021 ✓ ACK'D
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                    </div>
                </section>

                <section className="bg-ink px-14 py-16 text-paper">
                    <div className="mx-auto max-w-[1200px]">
                        <div className={cn(kicker, 'text-sun')}>
                            03 — THE OTHER SIDE
                        </div>
                        <div className="mt-2.5 flex items-center gap-14">
                            <Reveal className="flex-1">
                                <div className="font-display text-[38px] leading-[1.1] font-bold tracking-[-.02em]">
                                    Your client sees the same numbers.
                                    <br />
                                    <span className="text-ash">
                                        No discussions.
                                    </span>
                                </div>
                                <div className="mt-4 max-w-[440px] text-[14.5px] leading-[1.65] text-pretty text-fog">
                                    The client portal shows commitments,
                                    milestones, decisions and their own overdue
                                    actions — never your margin. When both sides
                                    watch the same ledger all along, the final
                                    invoice is a formality, not a fight.
                                </div>
                                <div className="mt-5.5 flex gap-6.5 text-[12.5px] text-fog">
                                    <span>✓ approvals in one click</span>
                                    <span>✓ evidence attached</span>
                                    <span>✓ margins never exposed</span>
                                </div>
                            </Reveal>
                            <Reveal
                                delay={0.15}
                                className="w-[380px] flex-none border-2 border-paper bg-paper px-6 py-5.5 text-ink"
                            >
                                <div className="flex items-baseline gap-2">
                                    <b className="text-[14px]">
                                        Your project with Northbound
                                    </b>
                                    <span className="ml-auto font-plex-mono text-[10px] text-stone">
                                        CLIENT VIEW
                                    </span>
                                </div>
                                <div className="mt-3 h-2 bg-sand-300">
                                    <div className="h-full w-[40%] bg-moss" />
                                </div>
                                <div className="mt-[5px] font-plex-mono text-[11px] text-stone">
                                    2/5 MILESTONES ACCEPTED · €76,100 INVOICED
                                </div>
                                <div className="mt-3.5 border-[1.5px] border-rust bg-white px-3.5 py-[11px] text-[12px]">
                                    <b className="text-rust">
                                        Your actions are holding the project:
                                    </b>
                                    <div className="mt-1.5 grid gap-1">
                                        <span>
                                            1. Provide carrier test data —{' '}
                                            <b>11 days overdue</b>
                                        </span>
                                        <span>
                                            2. Decide CR-07 (€18,400) — by
                                            Wednesday
                                        </span>
                                    </div>
                                </div>
                                <div className="mt-2.5 text-[11px] text-stone">
                                    No tickets. No boards. No margins. Just what
                                    was agreed, what changed, and what's owed.
                                </div>
                            </Reveal>
                        </div>
                    </div>
                </section>

                <section
                    id="toolkit"
                    className="mx-auto max-w-[1200px] px-14 pt-19 pb-7.5"
                >
                    <div className={cn(kicker, 'text-rust')}>
                        04 — THE RECORD
                    </div>
                    <Reveal className={cn(sectionTitle, 'leading-[1.1]')}>
                        Everything on the record.
                    </Reveal>
                    <div className="mt-9 grid grid-cols-4 gap-3">
                        <Reveal className="col-span-2">
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-white px-6.5 py-6',
                                    lift,
                                )}
                            >
                                <div className={cardLabel}>
                                    IMMUTABLE BASELINES
                                </div>
                                <div className={cardTitle}>
                                    Every approval mints a{' '}
                                    <span className={highlight}>
                                        new version
                                    </span>
                                </div>
                                <div className="mt-2 text-[13px] leading-[1.6] text-pretty text-stone">
                                    Scope is versioned like code. Nothing edited
                                    in place, nothing lost — every euro traces
                                    back to the version that authorised it.
                                </div>
                                <div className="mt-4 flex items-center gap-2 font-plex-mono text-[11px]">
                                    <span className="border-[1.5px] border-ink bg-paper px-2.5 py-[5px]">
                                        v1 · €230.6k
                                    </span>
                                    <span>→</span>
                                    <span className="border-[1.5px] border-ink bg-paper px-2.5 py-[5px]">
                                        v2 · €238.1k
                                    </span>
                                    <span>→</span>
                                    <span className="border-[1.5px] border-ink bg-paper px-2.5 py-[5px]">
                                        v3 · €249.0k
                                    </span>
                                    <span>→</span>
                                    <span className="bg-ink px-2.5 py-[5px] text-paper">
                                        v4 · DRAFT
                                    </span>
                                </div>
                            </div>
                        </Reveal>
                        <Reveal delay={0.08} className="col-span-2">
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-white px-6.5 py-6',
                                    lift,
                                )}
                            >
                                <div className={cardLabel}>PORTFOLIO VIEW</div>
                                <div className={cardTitle}>
                                    Every engagement's position, one screen
                                </div>
                                <div className="mt-4 grid gap-[7px] font-plex-mono text-[11.5px]">
                                    {[
                                        {
                                            name: 'NORTHBOUND',
                                            width: 'w-[72%]',
                                            delta: '+2.1pt',
                                            deltaColor: 'text-moss',
                                        },
                                        {
                                            name: 'HELIX',
                                            width: 'w-[44%]',
                                            delta: '+0.8pt',
                                            deltaColor: 'text-moss',
                                        },
                                        {
                                            name: 'PORTA',
                                            width: 'w-[31%]',
                                            delta: '−1.4pt',
                                            deltaColor: 'text-rust',
                                        },
                                    ].map((engagement) => (
                                        <div
                                            key={engagement.name}
                                            className="flex items-center gap-2.5"
                                        >
                                            <span className="w-[110px] flex-none">
                                                {engagement.name}
                                            </span>
                                            <div className="h-3 flex-1 bg-sand-200">
                                                <div
                                                    className={cn(
                                                        'h-full bg-ink',
                                                        engagement.width,
                                                    )}
                                                />
                                            </div>
                                            <b
                                                className={cn(
                                                    'w-[52px] text-right',
                                                    engagement.deltaColor,
                                                )}
                                            >
                                                {engagement.delta}
                                            </b>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </Reveal>
                        <Reveal>
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-white px-6.5 py-6',
                                    lift,
                                )}
                            >
                                <div className={cardLabel}>DECISION LEDGER</div>
                                <div className={cardTitle}>
                                    Who decided, when, on what evidence
                                </div>
                                <div className="mt-3.5 grid gap-1.5 font-plex-mono text-[11px] text-soot">
                                    <span>
                                        D-019 · CR-06 APPROVED ·{' '}
                                        <span className="text-moss">✓</span>
                                    </span>
                                    <span>
                                        D-020 · CREEP → IN-SCOPE ·{' '}
                                        <span className="text-moss">✓</span>
                                    </span>
                                    <span>
                                        D-021 · DELAY ATTRIBUTED ·{' '}
                                        <span className="text-moss">✓</span>
                                    </span>
                                </div>
                            </div>
                        </Reveal>
                        <Reveal delay={0.06}>
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-white px-6.5 py-6',
                                    lift,
                                )}
                            >
                                <div className={cardLabel}>ACCEPTANCE</div>
                                <div className={cardTitle}>
                                    Accepted means signed, not assumed
                                </div>
                                <div className="mt-3.5 grid gap-1.5 text-[12px]">
                                    <div className="flex items-baseline gap-2">
                                        <b>API integration</b>
                                        <span className="ml-auto font-plex-mono text-[10.5px] text-moss">
                                            ✓ ACCEPTED
                                        </span>
                                    </div>
                                    <div className="flex items-baseline gap-2">
                                        <b>Dispatch UI</b>
                                        <span className="ml-auto font-plex-mono text-[10.5px] text-ochre">
                                            IN REVIEW
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                        <Reveal delay={0.12} className="col-span-2">
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-ink px-6.5 py-6 text-paper',
                                    'transition-[translate,box-shadow] duration-200 hover:-translate-x-[3px] hover:-translate-y-[3px] hover:shadow-[6px_6px_0_var(--color-rust)]',
                                )}
                            >
                                <div className="font-plex-mono text-[11px] font-semibold text-ash">
                                    WEEKLY REPORTS
                                </div>
                                <div className={cardTitle}>
                                    Monday morning,{' '}
                                    <span className={cn(highlight, 'text-ink')}>
                                        evidence attached
                                    </span>
                                </div>
                                <div className="mt-2 text-[13px] leading-[1.6] text-pretty text-fog">
                                    One page: what moved, what changed, what's
                                    owed and by whom. Every line links to the
                                    record behind it — no narrative, no spin.
                                </div>
                                <div className="mt-3 font-plex-mono text-[10.5px] text-ash">
                                    WK 32 REPORT · 14 LINKED RECORDS · SENT TO 6
                                    STAKEHOLDERS
                                </div>
                            </div>
                        </Reveal>
                        <Reveal className="col-span-2">
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-white px-6.5 py-6',
                                    lift,
                                )}
                            >
                                <div className={cardLabel}>
                                    DEPENDENCY REGISTER
                                </div>
                                <div className={cardTitle}>
                                    Client-owed items with dates and owners
                                </div>
                                <div className="mt-3.5 grid font-plex-mono text-[11.5px]">
                                    <div className="flex border-b border-sand-300 py-1.5">
                                        <span>DEP-3 carrier test data</span>
                                        <span className="ml-auto text-rust">
                                            11d LATE
                                        </span>
                                    </div>
                                    <div className="flex py-1.5">
                                        <span>DEP-5 depot access list</span>
                                        <span className="ml-auto text-moss">
                                            ON TIME
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                        <Reveal delay={0.08} className="col-span-2">
                            <div
                                className={cn(
                                    'h-full border-2 border-ink bg-white px-6.5 py-6',
                                    lift,
                                )}
                            >
                                <div className={cardLabel}>INTEGRATIONS</div>
                                <div className={cardTitle}>
                                    Meets the work where it lives
                                </div>
                                <div className="mt-2 text-[13px] leading-[1.6] text-pretty text-stone">
                                    Two-way sync with the delivery tool — no
                                    double entry, no new board to maintain.
                                </div>
                                <div className="mt-3.5 flex gap-2 font-plex-mono text-[10.5px] font-semibold">
                                    <span className="border-[1.5px] border-ink bg-paper px-2.5 py-1">
                                        JIRA
                                    </span>
                                    <span className="border-[1.5px] border-ink bg-paper px-2.5 py-1">
                                        LINEAR
                                    </span>
                                    <span className="border-[1.5px] border-ink bg-paper px-2.5 py-1">
                                        STANDALONE
                                    </span>
                                </div>
                            </div>
                        </Reveal>
                    </div>
                </section>

                <section
                    id="pricing"
                    className="mx-auto max-w-[1200px] px-14 py-19"
                >
                    <div className={cn(kicker, 'text-moss')}>05 — PRICING</div>
                    <Reveal className={sectionTitle}>
                        One recovered change request pays for the year.
                    </Reveal>
                    <Reveal
                        delay={0.1}
                        className="mt-9 grid grid-cols-3 border-2 border-ink bg-white"
                    >
                        <div className="border-r border-sand-400 px-8 py-7.5">
                            <div className={cardLabel}>SOLO</div>
                            <div className="mt-2 font-display text-[34px] font-bold">
                                €0
                            </div>
                            <div className="text-[12px] text-stone">
                                1 active engagement
                            </div>
                            <div className="mt-4.5 grid gap-[7px] text-[12.5px] text-soot">
                                <span>✓ Scope-creep radar</span>
                                <span>✓ Change requests & approvals</span>
                                <span>✓ Client portal</span>
                                <span>✓ Jira or Linear</span>
                            </div>
                            <Link
                                href={login()}
                                className={cn(
                                    btnOutline,
                                    'mt-5 block py-2.5 text-[12.5px] hover:bg-paper',
                                )}
                            >
                                START FREE
                            </Link>
                        </div>
                        <div className="relative border-r border-sand-400 bg-ink px-8 py-7.5 text-paper">
                            <div className="absolute -top-0.5 right-4 bg-sun px-2 py-1 font-plex-mono text-[10px] font-semibold text-ink">
                                MOST AGENCIES
                            </div>
                            <div className="font-plex-mono text-[11px] font-semibold text-ash">
                                STUDIO
                            </div>
                            <div className="mt-2 font-display text-[34px] font-bold">
                                €89
                                <span className="text-[14px] text-ash">
                                    /engagement/mo
                                </span>
                            </div>
                            <div className="text-[12px] text-ash">
                                up to 25 active engagements
                            </div>
                            <div className="mt-4.5 grid gap-[7px] text-[12.5px] text-bone">
                                <span>✓ Everything in Solo</span>
                                <span>
                                    ✓ Delay attribution & impact letters
                                </span>
                                <span>✓ Weekly evidence-backed reports</span>
                                <span>✓ Decision & audit ledger</span>
                                <span>✓ Portfolio margin view</span>
                            </div>
                            <Link
                                href={login()}
                                className={cn(
                                    btn,
                                    'mt-5 block bg-sun py-2.5 text-[12.5px] text-ink hover:bg-white',
                                )}
                            >
                                GET STUDIO →
                            </Link>
                        </div>
                        <div className="px-8 py-7.5">
                            <div className={cardLabel}>FIRM</div>
                            <div className="mt-2 font-display text-[34px] font-bold">
                                Custom
                            </div>
                            <div className="text-[12px] text-stone">
                                unlimited · SSO · DPA
                            </div>
                            <div className="mt-4.5 grid gap-[7px] text-[12.5px] text-soot">
                                <span>✓ Everything in Studio</span>
                                <span>✓ SSO / SAML & audit export</span>
                                <span>✓ Custom approval chains</span>
                                <span>✓ Onboarding with your templates</span>
                            </div>
                            <a
                                href="mailto:hello@baseline.pm"
                                className={cn(
                                    btnOutline,
                                    'mt-5 block py-2.5 text-[12.5px] hover:bg-paper',
                                )}
                            >
                                TALK TO US
                            </a>
                        </div>
                    </Reveal>
                    <div className="mt-3 font-plex-mono text-[11.5px] text-stone">
                        CLIENT USERS ARE ALWAYS FREE — APPROVERS, VIEWERS,
                        SPONSORS. NO SEAT MATH.
                    </div>
                </section>

                <section
                    id="manifesto"
                    className="border-t-2 border-ink bg-white px-14 py-19"
                >
                    <Reveal className="mx-auto max-w-[760px]">
                        <div className={cn(kicker, 'text-rust')}>
                            06 — MANIFESTO
                        </div>
                        <div className="mt-3.5 font-display text-[30px] leading-[1.25] font-bold tracking-[-.02em]">
                            Scope creep isn't a client problem.
                            <br />
                            It's a{' '}
                            <span className="bg-sun px-1.5">
                                record-keeping
                            </span>{' '}
                            problem.
                        </div>
                        <div className="mt-5 text-[15px] leading-[1.75] text-pretty text-soot">
                            Clients don't set out to get free work. Teams don't
                            set out to give it away. It happens because the
                            agreement lives in a PDF nobody opens, while the
                            work lives in a tool the contract has never heard
                            of. Every undocumented favour, every "quick
                            addition", every slipped dependency is a small
                            silent renegotiation — always in the same direction.
                        </div>
                        <div className="mt-3.5 text-[15px] leading-[1.75] text-pretty text-soot">
                            Baseline's bet: if keeping the record is effortless,
                            the record keeps you. Decide who pays for every hour
                            — deliberately, visibly, at the moment it happens —
                            and fixed price becomes what it was supposed to be:
                            a fair deal for both sides.
                        </div>
                        <div className="mt-7.5 flex gap-3">
                            <Link
                                href={login()}
                                className={cn(btnDark, ctaSize)}
                            >
                                SEE YOUR POSITION →
                            </Link>
                            <span
                                className={cn(
                                    btnOutline,
                                    ctaSize,
                                    'hover:bg-paper',
                                )}
                            >
                                READ THE FULL MANIFESTO
                            </span>
                        </div>
                    </Reveal>
                </section>

                <footer className="flex items-start gap-10 border-t-2 border-ink bg-paper px-14 py-9 text-[12px] text-stone">
                    <div>
                        <div className="font-display text-[15px] font-bold text-ink">
                            BASELINE<span className="text-rust">.</span>
                        </div>
                        <div className="mt-1.5 max-w-[220px] leading-[1.5]">
                            Delivery governance for agencies running fixed-price
                            work.
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <b className="text-ink">Product</b>
                        <span>Scope-creep radar</span>
                        <span>Change control</span>
                        <span>Client portal</span>
                        <span>Integrations</span>
                    </div>
                    <div className="grid gap-1.5">
                        <b className="text-ink">Company</b>
                        <span>Manifesto</span>
                        <span>Pricing</span>
                        <span>Security</span>
                        <span>Contact</span>
                    </div>
                    <div className="ml-auto font-plex-mono text-[11px]">
                        © 2026 BASELINE · GDPR · EU-HOSTED
                    </div>
                </footer>
            </div>
        </>
    );
}
