import { Head } from '@inertiajs/react';
import { dashboard } from '@/routes';

const stats = [
    { label: 'Active engagements', value: '0' },
    { label: 'Open change requests', value: '0' },
    { label: 'Burn weeks logged', value: '0' },
];

export default function Dashboard() {
    return (
        <>
            <Head title="Overview" />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Overview
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        Control room
                    </h1>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className="border-[1.5px] border-ink p-4 dark:border-paper"
                        >
                            <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                {stat.label}
                            </div>
                            <div className="mt-2 font-plex-mono text-[32px] font-semibold">
                                {stat.value}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                    <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                        Nothing to report yet
                    </div>
                    <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                        Once engagements, baselines and burn weeks are tracked,
                        this overview shows the commercial position across your
                        portfolio.
                    </p>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Overview',
            href: dashboard(),
        },
    ],
};
