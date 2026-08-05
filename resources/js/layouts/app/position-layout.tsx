import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import EngagementPositionRail from '@/components/engagement-position-rail';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as customers } from '@/routes/customers';
import { index as engagements } from '@/routes/engagements';
import { show as organization } from '@/routes/organization';
import type {
    Auth,
    BreadcrumbItem,
    EngagementPositionSummary,
    UserRole,
} from '@/types';

const managingRoles: UserRole[] = [
    'owner',
    'delivery_manager',
    'commercial_manager',
];

function tabsFor(role: UserRole) {
    return [
        { title: 'Overview', href: dashboard() },
        { title: 'Engagements', href: engagements() },
        ...(managingRoles.includes(role)
            ? [{ title: 'Customers', href: customers() }]
            : []),
        { title: 'Organization', href: organization() },
    ];
}

/**
 * Stubbed commercial position blocks for the left rail. Real figures arrive
 * with the engagement/burn issues; pages can replace the rail via the `rail`
 * layout prop.
 */
function PositionRail() {
    const blocks = [
        { label: 'Contracted', value: '€ —' },
        { label: 'Burned', value: '€ —' },
        { label: 'Position', value: '€ —' },
    ];

    return (
        <div className="flex flex-col gap-3">
            <div className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                Commercial position
            </div>
            {blocks.map((block) => (
                <div
                    key={block.label}
                    className="border-[1.5px] border-ink bg-paper p-3 dark:border-paper dark:bg-ink"
                >
                    <div className="font-plex-mono text-[11px] font-semibold text-stone dark:text-fog">
                        {block.label.toUpperCase()}
                    </div>
                    <div className="mt-1 font-plex-mono text-[20px] font-semibold">
                        {block.value}
                    </div>
                </div>
            ))}
            <p className="text-[12px] leading-relaxed text-stone dark:text-fog">
                Your position appears here once engagements are tracked.
            </p>
        </div>
    );
}

export default function PositionLayout({
    breadcrumbs = [],
    rail,
    position,
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    rail?: ReactNode;
    /**
     * Plain-data engagement position for the rail. Passed via
     * setLayoutProps by engagement pages — serializable on purpose: JSX in
     * layout props re-renders forever (the layout-props store deep-compares
     * with isEqual, which never matches fresh React elements).
     */
    position?: EngagementPositionSummary;
    children: ReactNode;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { url } = usePage();
    const tabs = tabsFor(auth.user.role);

    return (
        <div className="flex min-h-screen flex-col bg-paper text-ink dark:bg-ink dark:text-paper">
            <header className="border-b-[1.5px] border-ink bg-paper dark:border-paper dark:bg-ink">
                <div className="flex h-14 items-stretch justify-between gap-4 pr-4 pl-5">
                    <div className="flex items-stretch gap-8">
                        <Link
                            href={dashboard()}
                            className="flex items-center font-display text-[17px] font-bold tracking-[-0.01em]"
                            prefetch
                        >
                            Baseline<span className="text-rust">.</span>
                        </Link>
                        <nav className="flex items-stretch overflow-x-auto">
                            {tabs.map((tab) => {
                                const isActive = url.startsWith(tab.href.url);

                                return (
                                    <Link
                                        key={tab.title}
                                        href={tab.href}
                                        prefetch
                                        className={cn(
                                            'flex items-center border-b-2 px-4 font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase transition-colors',
                                            isActive
                                                ? 'border-rust text-ink dark:text-paper'
                                                : 'border-transparent text-stone hover:text-ink dark:text-fog dark:hover:text-paper',
                                        )}
                                    >
                                        {tab.title}
                                    </Link>
                                );
                            })}
                        </nav>
                    </div>
                    <div className="flex items-center">
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex cursor-pointer items-center gap-2 border-[1.5px] border-transparent px-2 py-1 font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase hover:border-ink dark:hover:border-paper">
                                {auth.user.name}
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <UserMenuContent user={auth.user} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </header>

            <div className="flex flex-1">
                <aside className="hidden w-64 shrink-0 border-r-[1.5px] border-ink p-4 lg:block dark:border-paper">
                    {rail ??
                        (position !== undefined ? (
                            <EngagementPositionRail position={position} />
                        ) : (
                            <PositionRail />
                        ))}
                </aside>

                <main className="min-w-0 flex-1">
                    {breadcrumbs.length > 0 && (
                        <div className="border-b-[1.5px] border-ink px-6 py-3 dark:border-paper">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                    <div className="p-6">{children}</div>
                </main>
            </div>
        </div>
    );
}
