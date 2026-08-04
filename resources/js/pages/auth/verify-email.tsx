// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { authOutlineButton, authSuccessBox } from '@/lib/auth-form';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className={`${authSuccessBox} mb-4`}>
                    <b>Link sent ✓</b> — a new verification link is on its way
                    to your email address.
                </div>
            )}

            <Form {...send.form()}>
                {({ processing }) => (
                    <Button
                        variant="outline"
                        className={authOutlineButton}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Resend verification email
                    </Button>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
    footer: (
        <TextLink href={logout()} className="font-semibold">
            Log out
        </TextLink>
    ),
};
