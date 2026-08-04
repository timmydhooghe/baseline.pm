import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import MemberController from '@/actions/App/Http/Controllers/MemberController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { show as organization } from '@/routes/organization';
import type { Organization, PlanUsage, SelectOption, UserRole } from '@/types';

type Member = {
    id: string;
    name: string;
    email: string;
    role: UserRole;
    roleLabel: string;
    isCurrentUser: boolean;
};

type PendingInvitation = {
    id: string;
    email: string;
    roleLabel: string;
    expiresAt: string;
    isExpired: boolean;
};

type Props = {
    organization: Organization & { planLabel: string };
    planUsage: PlanUsage;
    members: Member[];
    invitations: PendingInvitation[];
    assignableRoles: SelectOption[];
    can: { manageMembers: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

export default function OrganizationShow({
    organization,
    planUsage,
    members,
    invitations,
    assignableRoles,
    can,
}: Props) {
    const [memberToRemove, setMemberToRemove] = useState<Member | null>(null);

    return (
        <>
            <Head title="Organization" />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Organization
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        {organization.name}
                    </h1>
                </div>

                <div className="flex flex-wrap items-baseline justify-between gap-2 border-[1.5px] border-ink px-4 py-3 dark:border-paper">
                    <span className={sectionLabel}>
                        Plan · {organization.planLabel}
                    </span>
                    <span className="font-plex-mono text-[12px] text-stone dark:text-fog">
                        {planUsage.limit === null
                            ? `${planUsage.activeCount} active engagements · no limit`
                            : `${planUsage.activeCount} of ${planUsage.limit} active engagements`}
                        {
                            ' — archived engagements don’t count, client users are always free'
                        }
                    </span>
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex items-center justify-between border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            Members · {members.length}
                        </span>
                        {!can.manageMembers && (
                            <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                Only the owner manages members
                            </span>
                        )}
                    </div>
                    <table className="w-full text-left text-[14px]">
                        <thead>
                            <tr className="border-b-[1.5px] border-ink dark:border-paper">
                                <th className={tableHeading}>Name</th>
                                <th className={tableHeading}>Email</th>
                                <th className={tableHeading}>Role</th>
                                {can.manageMembers && (
                                    <th className={tableHeading}>
                                        <span className="sr-only">Actions</span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member, memberIndex) => (
                                <tr
                                    key={member.id}
                                    className={cn(
                                        memberIndex < members.length - 1 &&
                                            'border-b border-ink/20 dark:border-paper/20',
                                    )}
                                >
                                    <td className="px-4 py-3 font-medium">
                                        {member.name}
                                        {member.isCurrentUser && (
                                            <span className="ml-2 font-plex-mono text-[11px] text-stone dark:text-fog">
                                                you
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-stone dark:text-fog">
                                        {member.email}
                                    </td>
                                    <td className="px-4 py-3">
                                        {can.manageMembers &&
                                        !member.isCurrentUser &&
                                        member.role !== 'owner' ? (
                                            <Select
                                                value={member.role}
                                                onValueChange={(role) =>
                                                    router.patch(
                                                        MemberController.update.url(
                                                            member.id,
                                                        ),
                                                        { role },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    size="sm"
                                                    className="rounded-none border-[1.5px] border-ink font-plex-mono text-[11px] font-semibold uppercase shadow-none dark:border-paper"
                                                    aria-label={`Role of ${member.name}`}
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {assignableRoles.map(
                                                        (role) => (
                                                            <SelectItem
                                                                key={role.value}
                                                                value={
                                                                    role.value
                                                                }
                                                            >
                                                                {role.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        ) : (
                                            <span
                                                className={cn(
                                                    'inline-block border-[1.5px] border-ink px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase dark:border-paper',
                                                    member.role === 'owner' &&
                                                        'bg-sun text-ink dark:text-ink',
                                                )}
                                            >
                                                {member.roleLabel}
                                            </span>
                                        )}
                                    </td>
                                    {can.manageMembers && (
                                        <td className="px-4 py-3 text-right">
                                            {!member.isCurrentUser && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                    onClick={() =>
                                                        setMemberToRemove(
                                                            member,
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {can.manageMembers && (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Pending invitations · {invitations.length}
                            </span>
                        </div>

                        {invitations.length > 0 && (
                            <table className="w-full text-left text-[14px]">
                                <thead>
                                    <tr className="border-b border-ink/20 dark:border-paper/20">
                                        <th className={tableHeading}>Email</th>
                                        <th className={tableHeading}>Role</th>
                                        <th className={tableHeading}>
                                            Expires
                                        </th>
                                        <th className={tableHeading}>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invitations.map((invitation) => (
                                        <tr
                                            key={invitation.id}
                                            className="border-b border-ink/20 dark:border-paper/20"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {invitation.email}
                                            </td>
                                            <td className="px-4 py-3 text-stone dark:text-fog">
                                                {invitation.roleLabel}
                                            </td>
                                            <td className="px-4 py-3 font-plex-mono text-[12px] text-stone dark:text-fog">
                                                {invitation.isExpired
                                                    ? 'Expired'
                                                    : invitation.expiresAt}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                    onClick={() =>
                                                        router.delete(
                                                            InvitationController.destroy.url(
                                                                invitation.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Revoke
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}

                        <Form
                            {...InvitationController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="flex flex-wrap items-end gap-3 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid min-w-56 flex-1 gap-1.5">
                                        <Label
                                            htmlFor="invite-email"
                                            className={sectionLabel}
                                        >
                                            Invite by email
                                        </Label>
                                        <Input
                                            id="invite-email"
                                            type="email"
                                            name="email"
                                            required
                                            placeholder="colleague@studio.eu"
                                            className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="invite-role"
                                            className={sectionLabel}
                                        >
                                            Role
                                        </Label>
                                        <Select
                                            name="role"
                                            defaultValue="member"
                                        >
                                            <SelectTrigger
                                                id="invite-role"
                                                className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {assignableRoles.map((role) => (
                                                    <SelectItem
                                                        key={role.value}
                                                        value={role.value}
                                                    >
                                                        {role.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.role} />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                        data-test="invite-member-button"
                                    >
                                        Send invite
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}
            </div>

            <Dialog
                open={memberToRemove !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setMemberToRemove(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>
                        Remove {memberToRemove?.name} from {organization.name}?
                    </DialogTitle>
                    <DialogDescription>
                        They immediately lose access to every engagement. Their
                        past actions stay in the audit trail.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            data-test="confirm-remove-member-button"
                            onClick={() => {
                                if (memberToRemove === null) {
                                    return;
                                }

                                router.delete(
                                    MemberController.destroy.url(
                                        memberToRemove.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            setMemberToRemove(null),
                                    },
                                );
                            }}
                        >
                            Remove member
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

OrganizationShow.layout = {
    breadcrumbs: [
        {
            title: 'Organization',
            href: organization(),
        },
    ],
};
