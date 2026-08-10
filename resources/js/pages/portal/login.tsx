import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { portalSectionLabel } from '@/layouts/portal-layout';
import { request as loginRequest } from '@/routes/portal/login';

/**
 * The portal's front door (FA-27). Stakeholders have no password: they ask
 * for a sign-in link by email, and the personally signed link establishes
 * the session on the `stakeholder` guard. The screen never confirms whether
 * an address is known.
 */
export default function PortalLogin() {
    return (
        <>
            <Head title="Client portal" />
            <div className="flex min-h-screen items-center justify-center bg-paper p-6 font-sans text-ink">
                <div className="w-full max-w-md border-[1.5px] border-ink bg-paper p-8">
                    <div className="flex items-baseline gap-3">
                        <div className="font-display text-[20px] font-bold tracking-[-0.01em]">
                            Baseline<span className="text-rust">.</span>
                        </div>
                        <span className="font-plex-mono text-[10px] font-semibold tracking-[0.14em] text-stone uppercase">
                            Client portal
                        </span>
                    </div>
                    <h1 className="mt-6 font-display text-[24px] font-bold tracking-[-0.02em]">
                        Sign in
                    </h1>
                    <p className="mt-2 text-[14px] leading-relaxed text-stone">
                        This is where you follow the engagements your team runs
                        with us — scope, progress, decisions and everything
                        awaiting your approval.
                    </p>
                    <Form
                        {...loginRequest.form()}
                        resetOnSuccess
                        className="mt-6 flex flex-col gap-3"
                    >
                        {({ processing, errors, wasSuccessful }) => (
                            <>
                                <label
                                    htmlFor="portal-email"
                                    className={portalSectionLabel}
                                >
                                    Your email
                                </label>
                                <input
                                    id="portal-email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder="you@company.com"
                                    className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[14px] outline-none placeholder:text-stone/60"
                                    data-test="portal-email"
                                />
                                <InputError message={errors.email} />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust"
                                    data-test="portal-request-link"
                                >
                                    {processing
                                        ? 'Sending…'
                                        : 'Email me a sign-in link →'}
                                </Button>
                                {wasSuccessful && (
                                    <p
                                        className="border-[1.5px] border-moss px-3 py-2 font-plex-mono text-[12px] text-moss"
                                        data-test="portal-link-sent"
                                    >
                                        If we know this address, a sign-in link
                                        is on its way — check your inbox.
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                    <p className="mt-6 border-[1.5px] border-ink bg-sun/40 p-3 font-plex-mono text-[12px]">
                        No password needed: the link is personal and expires
                        after 30 minutes. Review links from our emails sign you
                        in the same way.
                    </p>
                </div>
            </div>
        </>
    );
}
