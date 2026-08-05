import { router, usePage } from '@inertiajs/react';
import BaselineController from '@/actions/App/Http/Controllers/BaselineController';
import BaselineReview from '@/components/baseline-review';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import type { BaselineView, SelectOption } from '@/types';

type Props = {
    baseline: BaselineView;
    commercialModels: SelectOption[];
    executionModes: SelectOption[];
};

export default function BaselineStepSubmit({
    baseline,
    commercialModels,
    executionModes,
}: Props) {
    const { errors } = usePage().props;

    const submit = () =>
        router.post(
            BaselineController.submit.url(baseline.id),
            {},
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-4">
            <div className="border-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                    Step 6 · Submit for approval
                </span>
                <p className="mt-1 max-w-2xl text-[13px] text-stone dark:text-fog">
                    Submitting freezes an immutable review snapshot. The
                    customer approver reviews it in the portal with every cost
                    and margin figure stripped — approval makes this baseline v
                    {baseline.version} and activates the engagement; a rejection
                    or clarification request returns it to draft with the
                    snapshot preserved.
                </p>
            </div>

            <BaselineReview
                baseline={baseline}
                commercialModels={commercialModels}
                executionModes={executionModes}
            />

            <InputError message={errors.checks} />

            <div className="flex justify-end">
                <Button
                    type="button"
                    onClick={submit}
                    disabled={!baseline.canSubmit}
                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust disabled:opacity-40 dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                    data-test="baseline-submit-button"
                >
                    Submit baseline v{baseline.version} for approval
                </Button>
            </div>
        </div>
    );
}
