import { Head } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { show as organization } from '@/routes/organization';
import type { Organization, UserRole } from '@/types';

type Member = {
    id: string;
    name: string;
    email: string;
    role: UserRole;
    roleLabel: string;
};

type Props = {
    organization: Organization;
    members: Member[];
};

export default function OrganizationShow({ organization, members }: Props) {
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

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex items-center justify-between border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                            Members · {members.length}
                        </span>
                        <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                            Read-only — invitations land with a later milestone
                        </span>
                    </div>
                    <table className="w-full text-left text-[14px]">
                        <thead>
                            <tr className="border-b-[1.5px] border-ink dark:border-paper">
                                <th className="px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                                    Name
                                </th>
                                <th className="px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                                    Email
                                </th>
                                <th className="px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                                    Role
                                </th>
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
                                    </td>
                                    <td className="px-4 py-3 text-stone dark:text-fog">
                                        {member.email}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={cn(
                                                'inline-block border-[1.5px] border-ink px-2 py-0.5 font-plex-mono text-[11px] font-semibold uppercase dark:border-paper',
                                                member.role === 'owner' &&
                                                    'bg-sun text-ink dark:text-ink',
                                            )}
                                        >
                                            {member.roleLabel}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
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
