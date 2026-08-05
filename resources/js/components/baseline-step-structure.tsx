import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import BaselineItemController from '@/actions/App/Http/Controllers/BaselineItemController';
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
import type {
    BaselineItemType,
    BaselineItemView,
    BaselineMemberOption,
    BaselineView,
} from '@/types';

type Props = {
    baseline: BaselineView;
    members: BaselineMemberOption[];
    onContinue: () => void;
};

type Section = {
    type: BaselineItemType;
    label: string;
    addLabel: string;
    hint: string;
};

const SECTIONS: Section[] = [
    {
        type: 'deliverable',
        label: 'Deliverables',
        addLabel: 'Add deliverable',
        hint: 'Owner, commercial value and acceptance criteria — values must sum to the contract.',
    },
    {
        type: 'milestone',
        label: 'Milestones',
        addLabel: 'Add milestone',
        hint: 'Baseline date and what payment the milestone triggers.',
    },
    {
        type: 'assumption',
        label: 'Assumptions',
        addLabel: 'Add assumption',
        hint: 'What the plan takes for granted.',
    },
    {
        type: 'exclusion',
        label: 'Exclusions',
        addLabel: 'Add exclusion',
        hint: 'Explicitly out of scope.',
    },
    {
        type: 'responsibility',
        label: 'Customer responsibilities',
        addLabel: 'Add responsibility',
        hint: 'What the customer owes the delivery.',
    },
];

const fieldInput =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

type CriterionRow = {
    key: number;
    criterion: string;
    verificationMethod: string;
};

function ItemForm({
    baselineId,
    type,
    item,
    members,
    onClose,
}: {
    baselineId: string;
    type: BaselineItemType;
    item: BaselineItemView | null;
    members: BaselineMemberOption[];
    onClose: () => void;
}) {
    const [criteria, setCriteria] = useState<CriterionRow[]>(
        item === null
            ? []
            : item.acceptanceCriteria.map((criterion, index) => ({
                  key: index,
                  criterion: criterion.criterion,
                  verificationMethod: criterion.verificationMethod ?? '',
              })),
    );
    const [nextKey, setNextKey] = useState(criteria.length);

    const addCriterion = () => {
        setCriteria([
            ...criteria,
            { key: nextKey, criterion: '', verificationMethod: '' },
        ]);
        setNextKey(nextKey + 1);
    };

    const formProps =
        item === null
            ? BaselineItemController.store.form(baselineId)
            : BaselineItemController.update.form({
                  baseline: baselineId,
                  item: item.id,
              });

    return (
        <Form
            {...formProps}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onClose}
            className="flex flex-col gap-4 border-t border-ink/20 px-4 py-4 dark:border-paper/20"
        >
            {({ processing, errors }) => (
                <>
                    {item === null && (
                        <input type="hidden" name="type" value={type} />
                    )}

                    <div className="grid gap-6 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label>Title</Label>
                            <Input
                                name="title"
                                required
                                defaultValue={item?.title ?? ''}
                                placeholder="Checkout flow"
                                className={fieldInput}
                            />
                            <InputError message={errors.title} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Contract clause</Label>
                            <Input
                                name="clause_reference"
                                required
                                defaultValue={item?.clauseReference ?? ''}
                                placeholder="SOW §2.1, p. 4"
                                className={fieldInput}
                            />
                            <InputError message={errors.clause_reference} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Description</Label>
                        <textarea
                            name="description"
                            rows={2}
                            defaultValue={item?.description ?? ''}
                            className="rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[14px] shadow-none outline-none dark:border-paper"
                        />
                        <InputError message={errors.description} />
                    </div>

                    {type === 'deliverable' && (
                        <>
                            <div className="grid gap-6 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Owner</Label>
                                    <Select
                                        name="owner_id"
                                        defaultValue={item?.owner?.id}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Who delivers this?" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {members.map((member) => (
                                                <SelectItem
                                                    key={member.id}
                                                    value={member.id}
                                                >
                                                    {member.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.owner_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Commercial value (€)</Label>
                                    <Input
                                        name="value"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        defaultValue={
                                            item?.value
                                                ? String(
                                                      item.value.amount / 100,
                                                  )
                                                : ''
                                        }
                                        placeholder="48000"
                                        className={`${fieldInput} text-right font-plex-mono`}
                                    />
                                    <InputError message={errors.value} />
                                </div>
                            </div>

                            <div className="flex flex-col gap-3">
                                <span className={sectionLabel}>
                                    Acceptance criteria
                                </span>
                                {criteria.map((row, index) => (
                                    <div
                                        key={row.key}
                                        className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                    >
                                        <div>
                                            <Input
                                                name={`acceptance_criteria[${index}][criterion]`}
                                                defaultValue={row.criterion}
                                                required
                                                placeholder="All payment methods pass UAT"
                                                aria-label={`Criterion ${index + 1}`}
                                                className={fieldInput}
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `acceptance_criteria.${index}.criterion`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Input
                                                name={`acceptance_criteria[${index}][verification_method]`}
                                                defaultValue={
                                                    row.verificationMethod
                                                }
                                                placeholder="Verified by: UAT sign-off"
                                                aria-label={`Verification method ${index + 1}`}
                                                className={fieldInput}
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `acceptance_criteria.${index}.verification_method`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                            onClick={() =>
                                                setCriteria(
                                                    criteria.filter(
                                                        (candidate) =>
                                                            candidate.key !==
                                                            row.key,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ))}
                                <div>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        className="rounded-none shadow-none"
                                        onClick={addCriterion}
                                    >
                                        Add criterion
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}

                    {type === 'milestone' && (
                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>Baseline date</Label>
                                <Input
                                    name="baseline_date"
                                    type="date"
                                    defaultValue={item?.baselineDate ?? ''}
                                    className={fieldInput}
                                />
                                <InputError message={errors.baseline_date} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Payment trigger</Label>
                                <Input
                                    name="payment_trigger"
                                    defaultValue={item?.paymentTrigger ?? ''}
                                    placeholder="30% on acceptance"
                                    className={fieldInput}
                                />
                                <InputError message={errors.payment_trigger} />
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            className="rounded-none shadow-none"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                            data-test="baseline-item-save"
                        >
                            {item === null ? 'Add item' : 'Save item'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function ItemSummary({ item }: { item: BaselineItemView }) {
    const facts: string[] = [];

    if (item.type === 'deliverable') {
        facts.push(item.owner === null ? 'No owner yet' : item.owner.name);
        facts.push(item.value === null ? 'No value yet' : item.value.formatted);
        facts.push(
            item.acceptanceCriteria.length === 0
                ? 'No acceptance criteria'
                : `${item.acceptanceCriteria.length} criteria`,
        );
    }

    if (item.type === 'milestone') {
        facts.push(item.baselineDate ?? 'No date yet');
        facts.push(item.paymentTrigger ?? 'No payment trigger yet');
    }

    return (
        <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
            {item.clauseReference}
            {facts.length > 0 && ` · ${facts.join(' · ')}`}
        </span>
    );
}

export default function BaselineStepStructure({
    baseline,
    members,
    onContinue,
}: Props) {
    const [openForm, setOpenForm] = useState<string | null>(null);

    const removeItem = (itemId: string) =>
        router.delete(
            BaselineItemController.destroy.url({
                baseline: baseline.id,
                item: itemId,
            }),
            { preserveScroll: true, preserveState: true },
        );

    return (
        <div className="flex flex-col gap-4">
            {SECTIONS.map((section) => {
                const items = baseline.items.filter(
                    (item) => item.type === section.type,
                );

                return (
                    <div
                        key={section.type}
                        className="border-[1.5px] border-ink dark:border-paper"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <div>
                                <span className={sectionLabel}>
                                    {section.label} · {items.length}
                                </span>
                                <p className="mt-0.5 text-[12px] text-stone dark:text-fog">
                                    {section.hint}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                className="rounded-none shadow-none"
                                data-test={`add-${section.type}-button`}
                                onClick={() =>
                                    setOpenForm(
                                        openForm === `add-${section.type}`
                                            ? null
                                            : `add-${section.type}`,
                                    )
                                }
                            >
                                {section.addLabel}
                            </Button>
                        </div>

                        {items.length > 0 && (
                            <ul className="divide-y divide-ink/20 dark:divide-paper/20">
                                {items.map((item) => (
                                    <li key={item.id}>
                                        <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                            <div className="flex flex-col">
                                                <span className="font-medium">
                                                    {item.title}
                                                </span>
                                                <ItemSummary item={item} />
                                            </div>
                                            <span className="flex gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-none font-plex-mono text-[11px] font-semibold uppercase"
                                                    onClick={() =>
                                                        setOpenForm(
                                                            openForm ===
                                                                `edit-${item.id}`
                                                                ? null
                                                                : `edit-${item.id}`,
                                                        )
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                                    onClick={() =>
                                                        removeItem(item.id)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </span>
                                        </div>
                                        {openForm === `edit-${item.id}` && (
                                            <ItemForm
                                                baselineId={baseline.id}
                                                type={item.type}
                                                item={item}
                                                members={members}
                                                onClose={() =>
                                                    setOpenForm(null)
                                                }
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {openForm === `add-${section.type}` && (
                            <ItemForm
                                baselineId={baseline.id}
                                type={section.type}
                                item={null}
                                members={members}
                                onClose={() => setOpenForm(null)}
                            />
                        )}
                    </div>
                );
            })}

            <div className="flex justify-end">
                <Button
                    type="button"
                    onClick={onContinue}
                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                    data-test="baseline-structure-continue"
                >
                    Continue →
                </Button>
            </div>
        </div>
    );
}
