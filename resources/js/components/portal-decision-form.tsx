import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { portalSectionLabel } from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';

export type PortalDecisionOption<TDecision extends string> = {
    value: TDecision;
    title: string;
    description: string;
};

/**
 * The portal's decision block (FA-13, FA-23, FA-24): a customer approver
 * picks one option, optionally says why, and the response is recorded
 * immutably against the frozen snapshot they are looking at. The form posts
 * to a freshly signed URL minted by the server — Wayfinder cannot sign.
 */
export default function PortalDecisionForm<TDecision extends string>({
    heading,
    options,
    defaultDecision,
    respondUrl,
    submitLabel,
    clarificationValue,
    footnote,
}: {
    heading: string;
    options: PortalDecisionOption<TDecision>[];
    defaultDecision: TDecision;
    respondUrl: string;
    submitLabel: string;
    clarificationValue: TDecision;
    footnote: string;
}) {
    const [decision, setDecision] = useState<TDecision>(defaultDecision);

    return (
        <div className="border-[1.5px] border-ink">
            <div className="border-b-[1.5px] border-ink px-4 py-3">
                <span className={portalSectionLabel}>{heading}</span>
            </div>
            <Form
                action={respondUrl}
                method="post"
                className="flex flex-col gap-4 px-4 py-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-2">
                            {options.map((option) => (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 border-[1.5px] px-3 py-2',
                                        decision === option.value
                                            ? 'border-rust'
                                            : 'border-ink/40 hover:border-ink',
                                    )}
                                    data-test={`decision-${option.value}`}
                                >
                                    <input
                                        type="radio"
                                        name="decision"
                                        value={option.value}
                                        checked={decision === option.value}
                                        onChange={() =>
                                            setDecision(option.value)
                                        }
                                        className="mt-1 accent-rust"
                                    />
                                    <span className="flex flex-col">
                                        <span className="text-[14px] font-semibold">
                                            {option.title}
                                        </span>
                                        <span className="text-[12px] text-stone">
                                            {option.description}
                                        </span>
                                    </span>
                                </label>
                            ))}
                            <InputError message={errors.decision} />
                        </div>
                        <div className="grid gap-2">
                            <label
                                htmlFor="response-comment"
                                className={portalSectionLabel}
                            >
                                Comment (optional)
                            </label>
                            <textarea
                                id="response-comment"
                                name="comment"
                                rows={3}
                                placeholder={
                                    decision === clarificationValue
                                        ? 'What should the delivery team clarify?'
                                        : 'Anything you want on the record with this decision.'
                                }
                                className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[13px] outline-none"
                                data-test="response-comment"
                            />
                            <InputError message={errors.comment} />
                        </div>
                        <div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust"
                                data-test="record-response"
                            >
                                {submitLabel}
                            </Button>
                        </div>
                        <p className="text-[12px] text-stone">{footnote}</p>
                    </>
                )}
            </Form>
        </div>
    );
}
