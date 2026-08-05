import { Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import BaselineReview from '@/components/baseline-review';
import BaselineStepChecks from '@/components/baseline-step-checks';
import BaselineStepCommercials from '@/components/baseline-step-commercials';
import BaselineStepContract from '@/components/baseline-step-contract';
import BaselineStepDetails from '@/components/baseline-step-details';
import BaselineStepStructure from '@/components/baseline-step-structure';
import BaselineStepSubmit from '@/components/baseline-step-submit';
import TextLink from '@/components/text-link';
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { show as baselineShow } from '@/routes/engagements/baseline';
import { show as rateCardShow } from '@/routes/organization/rate-card';
import type {
    BaselineMemberOption,
    BaselineRateCardView,
    BaselineView,
    EngagementStatus,
    SelectOption,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
        customerName: string;
    };
    baseline: BaselineView | null;
    rateCard: BaselineRateCardView | null;
    members: BaselineMemberOption[];
    commercialModels: SelectOption[];
    executionModes: SelectOption[];
    can: { manage: boolean; viewCommercials: boolean };
};

const STEPS = [
    'Details',
    'Contract',
    'Structure',
    'Commercials',
    'Completeness',
    'Submit',
];

export default function EngagementsBaseline({
    engagement,
    baseline,
    rateCard,
    members,
    commercialModels,
    executionModes,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Baseline', href: baselineShow(engagement.id) },
        ],
    });

    const [step, setStep] = useState(1);

    const isDraftWizard =
        can.manage && (baseline === null || baseline.status === 'draft');

    return (
        <>
            <Head title={`Baseline · ${engagement.name}`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Baseline builder · internal
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name} — baseline v
                            {baseline?.version ?? 1}
                        </h1>
                        <p className="mt-1 text-[14px] text-stone dark:text-fog">
                            for {engagement.customerName} ·{' '}
                            {engagement.statusLabel}
                        </p>
                    </div>
                    {baseline !== null && (
                        <span
                            className={cn(
                                'border-[1.5px] px-2.5 py-1 font-plex-mono text-[11px] font-semibold uppercase',
                                baseline.status === 'approved' &&
                                    'border-moss bg-moss text-paper',
                                baseline.status === 'awaiting_approval' &&
                                    'border-ochre bg-ochre text-ink',
                                baseline.status === 'draft' &&
                                    'border-ink text-ink dark:border-paper dark:text-paper',
                            )}
                        >
                            {baseline.statusLabel}
                        </span>
                    )}
                </div>

                {baseline !== null &&
                    baseline.status === 'awaiting_approval' && (
                        <div className="border-[1.5px] border-ochre px-4 py-3">
                            <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                                Submitted{' '}
                                {baseline.submittedAt !== null &&
                                    `on ${baseline.submittedAt}`}{' '}
                                — the review snapshot is frozen while the
                                customer approver decides in the portal.
                                Approval activates the engagement; rejection
                                returns the draft here.
                            </span>
                        </div>
                    )}

                {baseline !== null && baseline.status === 'approved' && (
                    <div className="border-[1.5px] border-moss px-4 py-3">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                            Approved{' '}
                            {baseline.approvedAt !== null &&
                                `on ${baseline.approvedAt}`}{' '}
                            — baseline v{baseline.version} is immutable. Every
                            change now goes through a change request, which
                            creates the next version.
                        </span>
                    </div>
                )}

                {isDraftWizard && baseline === null && rateCard === null && (
                    <div className="border-[1.5px] border-rust px-4 py-3">
                        <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                            No rate card published yet — a baseline pins the
                            current rate card version at creation, so{' '}
                            <TextLink href={rateCardShow()}>
                                publish your rate card
                            </TextLink>{' '}
                            first.
                        </span>
                    </div>
                )}

                {isDraftWizard ? (
                    <>
                        <ol className="flex flex-col gap-0 sm:flex-row sm:flex-wrap sm:items-center sm:gap-y-3">
                            {STEPS.map((label, index) => {
                                const number = index + 1;
                                const isCurrent = number === step;
                                const isDisabled =
                                    baseline === null && number > 1;

                                return (
                                    <li
                                        key={label}
                                        className="flex items-center"
                                    >
                                        <button
                                            type="button"
                                            disabled={isDisabled}
                                            onClick={() => setStep(number)}
                                            data-test={`baseline-step-${number}`}
                                            className={cn(
                                                'flex items-center gap-2 border-[1.5px] px-2.5 py-1 font-plex-mono text-[11px] font-semibold whitespace-nowrap uppercase',
                                                isCurrent &&
                                                    'border-rust bg-rust text-paper',
                                                !isCurrent &&
                                                    !isDisabled &&
                                                    'border-ink text-ink hover:border-rust hover:text-rust dark:border-paper dark:text-paper',
                                                isDisabled &&
                                                    'border-ink/30 text-stone dark:border-paper/30 dark:text-fog',
                                            )}
                                        >
                                            {number}. {label}
                                        </button>
                                        {number < STEPS.length && (
                                            <span
                                                aria-hidden
                                                className="mx-1 hidden h-[1.5px] w-4 bg-ink/30 sm:block dark:bg-paper/30"
                                            />
                                        )}
                                    </li>
                                );
                            })}
                        </ol>

                        {step === 1 && (
                            <BaselineStepDetails
                                engagementId={engagement.id}
                                customerName={engagement.customerName}
                                baseline={baseline}
                                commercialModels={commercialModels}
                                executionModes={executionModes}
                                onSaved={() => setStep(2)}
                            />
                        )}
                        {step === 2 && baseline !== null && (
                            <BaselineStepContract
                                baseline={baseline}
                                onContinue={() => setStep(3)}
                            />
                        )}
                        {step === 3 && baseline !== null && (
                            <BaselineStepStructure
                                baseline={baseline}
                                members={members}
                                onContinue={() => setStep(4)}
                            />
                        )}
                        {step === 4 && baseline !== null && (
                            <BaselineStepCommercials
                                baseline={baseline}
                                rateCard={rateCard}
                                onContinue={() => setStep(5)}
                            />
                        )}
                        {step === 5 && baseline !== null && (
                            <BaselineStepChecks
                                baseline={baseline}
                                canManage={can.manage}
                                onContinue={() => setStep(6)}
                            />
                        )}
                        {step === 6 && baseline !== null && (
                            <BaselineStepSubmit
                                baseline={baseline}
                                commercialModels={commercialModels}
                                executionModes={executionModes}
                            />
                        )}
                    </>
                ) : baseline !== null ? (
                    <BaselineReview
                        baseline={baseline}
                        commercialModels={commercialModels}
                        executionModes={executionModes}
                    />
                ) : (
                    <div className="border-[1.5px] border-ink p-10 text-center dark:border-paper">
                        <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                            No baseline yet
                        </div>
                        <p className="mx-auto mt-2 max-w-md text-[14px] text-stone dark:text-fog">
                            A delivery or commercial manager turns the signed
                            contract into the baseline: structured items,
                            derived cost budget and an immutable approval
                            snapshot.
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}
