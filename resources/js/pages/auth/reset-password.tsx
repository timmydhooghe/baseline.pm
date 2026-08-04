import { Form, Head } from '@inertiajs/react';
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
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <>
            <Head title="Reset password" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
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
                                    name="email"
                                    autoComplete="email"
                                    value={email}
                                    className={cn(
                                        authInput,
                                        'bg-[#efece4] text-stone',
                                    )}
                                    readOnly
                                />
                                <InputError
                                    message={errors.email}
                                    className={authError}
                                />
                            </div>

                            <div className="grid gap-[5px]">
                                <Label htmlFor="password" className={authLabel}>
                                    New password
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="new-password"
                                    autoFocus
                                    placeholder="12+ characters"
                                    passwordrules={passwordRules}
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
                                    autoComplete="new-password"
                                    placeholder="Repeat password"
                                    passwordrules={passwordRules}
                                    className={authInput}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                    className={authError}
                                />
                            </div>
                        </div>

                        <Button
                            type="submit"
                            className={cn(authSubmitButton, 'mt-4')}
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            RESET PASSWORD →
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Reset password',
    description: 'Please enter your new password below',
};
