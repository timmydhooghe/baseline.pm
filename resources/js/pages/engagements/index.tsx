import { Head } from '@inertiajs/react';
import { index as engagements } from '@/routes/engagements';

export default function EngagementsIndex() {
    return (
        <>
            <Head title="Engagements" />
            <div className="flex flex-col gap-6">
                <div>
                    <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                        Engagements
                    </div>
                    <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                        All engagements
                    </h1>
                </div>

                <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                    <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                        No engagements yet
                    </div>
                    <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                        The engagement lifecycle — rate cards, baselines, change
                        requests and burn tracking — lands with the upcoming
                        milestones.
                    </p>
                </div>
            </div>
        </>
    );
}

EngagementsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Engagements',
            href: engagements(),
        },
    ],
};
