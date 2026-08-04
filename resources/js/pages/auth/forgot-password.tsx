import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    authError,
    authInput,
    authLabel,
    authOutlineButton,
    authSubmitButton,
    authSuccessBox,
} from '@/lib/auth-form';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const linkSent = Boolean(status);

    return (
        <>
            <Head title="Reset password" />

            {linkSent && (
                <div className="mb-4 grid gap-3">
                    <div className={authSuccessBox}>
                        <b>Link sent ✓</b> — if that address has an account, the
                        email is on its way. Check spam before retrying.
                    </div>
                    <div className="font-plex-mono text-[10.5px] text-ash">
                        SINGLE-USE · EXPIRES IN 60 MIN
                    </div>
                </div>
            )}

            <Form {...email.form()}>
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-[5px]">
                            <Label htmlFor="email" className={authLabel}>
                                Work email
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="off"
                                autoFocus
                                placeholder="ev@northbound.eu"
                                className={authInput}
                            />
                            <InputError
                                message={errors.email}
                                className={authError}
                            />
                        </div>

                        <Button
                            className={cn(
                                linkSent ? authOutlineButton : authSubmitButton,
                                'mt-4',
                            )}
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner />}
                            {linkSent ? 'Resend link' : 'SEND RESET LINK →'}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ForgotPassword.layout = (props: { status?: string }) => ({
    title: 'Reset password',
    description: props.status
        ? ''
        : "We'll email you a single-use reset link. It expires in 60 minutes.",
    footer: (
        <TextLink href={login()} className="font-semibold">
            ← Back to sign in
        </TextLink>
    ),
});
