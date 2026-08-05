import { router } from '@inertiajs/react';
import BaselineController from '@/actions/App/Http/Controllers/BaselineController';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { BaselineView } from '@/types';

type Props = {
    baseline: BaselineView;
    canManage: boolean;
    onContinue: () => void;
};

export default function BaselineStepChecks({
    baseline,
    canManage,
    onContinue,
}: Props) {
    const acknowledge = (check: string) =>
        router.post(
            BaselineController.acknowledge.url(baseline.id),
            { check },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <div className="flex flex-col gap-4">
            <div className="border-[1.5px] border-ink dark:border-paper">
                <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                    <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                        Step 5 · Completeness check
                    </span>
                </div>
                <ul className="divide-y divide-ink/20 dark:divide-paper/20">
                    {baseline.checks.map((check) => (
                        <li
                            key={check.key}
                            className="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                        >
                            <div className="flex items-start gap-3">
                                <span
                                    aria-hidden
                                    className={cn(
                                        'mt-0.5 flex size-5 items-center justify-center border-[1.5px] font-plex-mono text-[11px] font-bold',
                                        check.passed
                                            ? 'border-moss bg-moss text-paper'
                                            : check.acknowledged
                                              ? 'border-ochre bg-ochre text-ink'
                                              : 'border-rust bg-rust text-paper',
                                    )}
                                >
                                    {check.passed
                                        ? '✓'
                                        : check.acknowledged
                                          ? '~'
                                          : '!'}
                                </span>
                                <div>
                                    <div className="font-medium">
                                        {check.label}
                                    </div>
                                    {!check.passed && check.detail !== '' && (
                                        <p className="mt-0.5 max-w-xl text-[13px] text-stone dark:text-fog">
                                            {check.detail}
                                        </p>
                                    )}
                                    {!check.passed && check.acknowledged && (
                                        <p className="mt-0.5 font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                            Acknowledged
                                            {check.acknowledgedBy !== null &&
                                                ` by ${check.acknowledgedBy}`}
                                        </p>
                                    )}
                                </div>
                            </div>
                            {!check.passed &&
                                !check.acknowledged &&
                                canManage && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-plex-mono text-[11px] font-semibold uppercase shadow-none dark:border-paper"
                                        data-test={`acknowledge-${check.key}-button`}
                                        onClick={() => acknowledge(check.key)}
                                    >
                                        Acknowledge
                                    </Button>
                                )}
                        </li>
                    ))}
                </ul>
            </div>

            <p className="text-[12px] text-stone dark:text-fog">
                Every warning must be fixed — or explicitly acknowledged —
                before the baseline can be submitted. Acknowledgements are
                frozen into the review snapshot.
            </p>

            <div className="flex justify-end">
                <Button
                    type="button"
                    onClick={onContinue}
                    disabled={!baseline.canSubmit}
                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust disabled:opacity-40 dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                    data-test="baseline-checks-continue"
                >
                    Continue to submit →
                </Button>
            </div>
        </div>
    );
}
