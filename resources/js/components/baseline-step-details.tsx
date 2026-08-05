import { Form } from '@inertiajs/react';
import BaselineController from '@/actions/App/Http/Controllers/BaselineController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { BaselineView, SelectOption } from '@/types';

type Props = {
    engagementId: string;
    customerName: string;
    baseline: BaselineView | null;
    commercialModels: SelectOption[];
    executionModes: SelectOption[];
    onSaved: () => void;
};

const fieldInput =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

function euros(amount: number) {
    return String(amount / 100);
}

export default function BaselineStepDetails({
    engagementId,
    customerName,
    baseline,
    commercialModels,
    executionModes,
    onSaved,
}: Props) {
    const formProps =
        baseline === null
            ? BaselineController.store.form(engagementId)
            : BaselineController.update.form(baseline.id);

    return (
        <div className="border-[1.5px] border-ink dark:border-paper">
            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                    Step 1 · Details
                </span>
            </div>
            <Form
                {...formProps}
                options={{ preserveScroll: true, preserveState: true }}
                onSuccess={onSaved}
                className="flex flex-col gap-6 px-4 py-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label>Customer</Label>
                            <p className="text-[14px] font-medium">
                                {customerName}
                            </p>
                            <p className="text-[12px] text-stone dark:text-fog">
                                Fixed by the engagement — the baseline commits
                                this customer to what follows.
                            </p>
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="baseline-commercial-model">
                                    Commercial model
                                </Label>
                                <Select
                                    name="commercial_model"
                                    defaultValue={
                                        baseline?.commercialModel ??
                                        'fixed_price'
                                    }
                                >
                                    <SelectTrigger id="baseline-commercial-model">
                                        <SelectValue placeholder="Pick a model" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {commercialModels.map((model) => (
                                            <SelectItem
                                                key={model.value}
                                                value={model.value}
                                            >
                                                {model.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.commercial_model} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="baseline-contract-value">
                                    Contract value (€)
                                </Label>
                                <Input
                                    id="baseline-contract-value"
                                    name="contract_value"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    placeholder="125000"
                                    defaultValue={
                                        baseline
                                            ? euros(
                                                  baseline.contractValue.amount,
                                              )
                                            : ''
                                    }
                                    className={`${fieldInput} text-right font-plex-mono`}
                                />
                                <InputError message={errors.contract_value} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="baseline-start-date">
                                    Start date
                                </Label>
                                <Input
                                    id="baseline-start-date"
                                    name="start_date"
                                    type="date"
                                    required
                                    defaultValue={baseline?.startDate ?? ''}
                                    className={fieldInput}
                                />
                                <InputError message={errors.start_date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="baseline-end-date">
                                    End date
                                </Label>
                                <Input
                                    id="baseline-end-date"
                                    name="end_date"
                                    type="date"
                                    required
                                    defaultValue={baseline?.endDate ?? ''}
                                    className={fieldInput}
                                />
                                <InputError message={errors.end_date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="baseline-execution-mode">
                                    Execution mode
                                </Label>
                                <Select
                                    name="execution_mode"
                                    defaultValue={
                                        baseline?.executionMode ?? 'standalone'
                                    }
                                >
                                    <SelectTrigger id="baseline-execution-mode">
                                        <SelectValue placeholder="Where is work tracked?" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {executionModes.map((mode) => (
                                            <SelectItem
                                                key={mode.value}
                                                value={mode.value}
                                            >
                                                {mode.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.execution_mode} />
                                <p className="text-[12px] text-stone dark:text-fog">
                                    The mode can change later without losing
                                    governance history.
                                </p>
                            </div>
                        </div>

                        <InputError message={errors.baseline} />

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={processing}
                                className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                data-test="baseline-details-submit"
                            >
                                {baseline === null
                                    ? 'Start baseline draft →'
                                    : 'Save details →'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}
