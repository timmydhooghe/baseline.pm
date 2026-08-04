import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    authError,
    authInput,
    authLabel,
    authSubmitButton,
    authSuccessBox,
} from '@/lib/auth-form';
import { cn } from '@/lib/utils';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Sign in" />

            {status && (
                <div className={cn(authSuccessBox, 'mb-4')}>{status}</div>
            )}

            <PasskeyVerify />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2.5">
                            <div className="grid gap-[5px]">
                                <Label htmlFor="email" className={authLabel}>
                                    Work email
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="ev@northbound.eu"
                                    className={authInput}
                                />
                                <InputError
                                    message={errors.email}
                                    className={authError}
                                />
                            </div>

                            <div className="grid gap-[5px]">
                                <div className="flex items-baseline">
                                    <Label
                                        htmlFor="password"
                                        className={authLabel}
                                    >
                                        Password
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto font-plex-mono text-[10.5px] text-ink hover:text-rust"
                                            tabIndex={5}
                                        >
                                            forgot?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="••••••••••••"
                                    className={authInput}
                                />
                                <InputError
                                    message={errors.password}
                                    className={authError}
                                />
                            </div>

                            <div className="mt-0.5 flex items-center gap-2.5">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                    className="rounded-none border-[1.5px] border-ink shadow-none"
                                />
                                <Label
                                    htmlFor="remember"
                                    className="text-[12.5px] font-normal text-stone"
                                >
                                    Remember me
                                </Label>
                            </div>
                        </div>

                        <Button
                            type="submit"
                            className={cn(authSubmitButton, 'mt-4')}
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            SIGN IN →
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Sign in',
    description: 'Back to your position.',
    footer: (
        <div className="border-[1.5px] border-sand-400 bg-[#efece4] px-3.5 py-2.5 text-left text-[11.5px] leading-[1.5] text-stone">
            Accounts are invite-only — ask your organization owner for an{' '}
            <b className="text-ink">invite</b>. Client? You&rsquo;ll get a
            portal link by email, no account needed here.
        </div>
    ),
};
