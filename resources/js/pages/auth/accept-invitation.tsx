import { Form, Head } from '@inertiajs/react';
import AcceptInvitationController from '@/actions/App/Http/Controllers/AcceptInvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    authError,
    authInput,
    authLabel,
    authSubmitButton,
} from '@/lib/auth-form';
import { cn } from '@/lib/utils';

type Props = {
    invitation: {
        token: string;
        email: string;
        roleLabel: string;
        organizationName: string;
        inviterName: string | null;
        isExpired: boolean;
    };
};

export default function AcceptInvitation({ invitation }: Props) {
    if (invitation.isExpired) {
        return (
            <>
                <Head title="Invitation expired" />
                <div className="border-[1.5px] border-rust bg-white px-4 py-3 text-[13px] text-ink">
                    This invitation has expired. Ask{' '}
                    <b>{invitation.inviterName ?? 'the owner'}</b> at{' '}
                    <b>{invitation.organizationName}</b> to send a new one.
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Join ${invitation.organizationName}`} />

            <div className="mb-4 border-[1.5px] border-sand-400 bg-[#efece4] px-3.5 py-2.5 text-[12.5px] leading-[1.5] text-stone">
                {invitation.inviterName ?? 'The owner'} invited you to join{' '}
                <b className="text-ink">{invitation.organizationName}</b> as{' '}
                <b className="text-ink">{invitation.roleLabel}</b>.
            </div>

            <Form
                {...AcceptInvitationController.store.form(invitation.token)}
                resetOnError={['password', 'password_confirmation']}
            >
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
                                    value={invitation.email}
                                    disabled
                                    className={cn(authInput, 'opacity-70')}
                                />
                            </div>

                            <div className="grid gap-[5px]">
                                <Label htmlFor="name" className={authLabel}>
                                    Your name
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    placeholder="Ada Verhoeven"
                                    className={authInput}
                                />
                                <InputError
                                    message={errors.name}
                                    className={authError}
                                />
                            </div>

                            <div className="grid gap-[5px]">
                                <Label htmlFor="password" className={authLabel}>
                                    Password
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="new-password"
                                    placeholder="••••••••••••"
                                    className={authInput}
                                />
                                <InputError
                                    message={errors.password}
                                    className={authError}
                                />
                            </div>

                            <div className="grid gap-[5px]">
                                <Label
                                    htmlFor="password_confirmation"
                                    className={authLabel}
                                >
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    placeholder="••••••••••••"
                                    className={authInput}
                                />
                            </div>
                        </div>

                        <Button
                            type="submit"
                            className={cn(authSubmitButton, 'mt-4')}
                            tabIndex={4}
                            disabled={processing}
                            data-test="accept-invitation-button"
                        >
                            {processing && <Spinner />}
                            JOIN {invitation.organizationName.toUpperCase()} →
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Accept your invitation',
    description: 'Set up your account to join your team.',
};
