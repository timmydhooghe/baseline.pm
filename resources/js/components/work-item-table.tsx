import { Form, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import WorkItemController from '@/actions/App/Http/Controllers/WorkItemController';
import WorkItemLinkController from '@/actions/App/Http/Controllers/WorkItemLinkController';
import WorkItemWorklogController from '@/actions/App/Http/Controllers/WorkItemWorklogController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { SelectOption, WorkItemView } from '@/types';

type Props = {
    engagementId: string;
    items: WorkItemView[];
    deliverables: { id: string; title: string }[];
    states: SelectOption[];
    canLink: boolean;
    canRecord: boolean;
};

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldInput =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const stateClasses: Record<WorkItemView['state'], string> = {
    todo: 'border-stone text-stone dark:border-fog dark:text-fog',
    in_progress: 'border-ochre text-ochre',
    done: 'border-moss text-moss',
    canceled:
        'border-stone/60 text-stone/60 line-through dark:border-fog/60 dark:text-fog/60',
};

function WorkItemFormFields({
    item,
    states,
    errors,
}: {
    item: WorkItemView | null;
    states: SelectOption[];
    errors: Record<string, string>;
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="work-item-title">Title</Label>
                <Input
                    id="work-item-title"
                    name="title"
                    required
                    defaultValue={item?.title ?? ''}
                    className={fieldInput}
                />
                <InputError message={errors.title} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="work-item-state">State</Label>
                    <Select name="state" defaultValue={item?.state ?? 'todo'}>
                        <SelectTrigger id="work-item-state">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {states.map((state) => (
                                <SelectItem
                                    key={state.value}
                                    value={state.value}
                                >
                                    {state.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.state} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="work-item-estimate">Estimate (days)</Label>
                    <Input
                        id="work-item-estimate"
                        name="estimate_days"
                        type="number"
                        step="0.25"
                        min="0"
                        defaultValue={
                            item?.estimate?.endsWith('d')
                                ? item.estimate.slice(0, -1)
                                : ''
                        }
                        className={`${fieldInput} text-right font-plex-mono`}
                    />
                    <InputError message={errors.estimate_days} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="work-item-type">Type</Label>
                    <Input
                        id="work-item-type"
                        name="type"
                        placeholder="Task, bug, story…"
                        defaultValue={item?.type ?? ''}
                        className={fieldInput}
                    />
                    <InputError message={errors.type} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="work-item-assignee">Assignee</Label>
                    <Input
                        id="work-item-assignee"
                        name="assignee_name"
                        defaultValue={item?.assigneeName ?? ''}
                        className={fieldInput}
                    />
                    <InputError message={errors.assignee_name} />
                </div>
            </div>
        </>
    );
}

export default function WorkItemTable({
    engagementId,
    items,
    deliverables,
    states,
    canLink,
    canRecord,
}: Props) {
    const { errors } = usePage().props;
    const [selected, setSelected] = useState<string[]>([]);
    const [target, setTarget] = useState<string>('');
    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<WorkItemView | null>(null);
    const [logging, setLogging] = useState<WorkItemView | null>(null);

    const toggle = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((selectedId) => selectedId !== id)
                : [...current, id],
        );

    const mapSelection = () =>
        router.post(
            WorkItemLinkController.store.url(engagementId),
            { work_item_ids: selected, baseline_item_id: target },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setTarget('');
                },
            },
        );

    const unlink = (item: WorkItemView) =>
        router.delete(WorkItemLinkController.destroy.url(item.id), {
            preserveScroll: true,
        });

    return (
        <div className="border-[1.5px] border-ink dark:border-paper">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                    Work items
                </span>
                {canRecord && (
                    <Dialog open={addOpen} onOpenChange={setAddOpen}>
                        <DialogTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                data-test="add-work-item-button"
                            >
                                Record work item
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Record a work item</DialogTitle>
                            <DialogDescription>
                                Manual items are the standalone execution mode —
                                they live alongside synced ones, so connecting a
                                tool later loses nothing.
                            </DialogDescription>
                            <Form
                                {...WorkItemController.store.form(engagementId)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setAddOpen(false)}
                                resetOnSuccess
                                className="flex flex-col gap-4"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <WorkItemFormFields
                                            item={null}
                                            states={states}
                                            errors={formErrors}
                                        />
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    variant="secondary"
                                                    type="button"
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                data-test="save-work-item-button"
                                            >
                                                Record →
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>

            {canLink && selected.length > 0 && (
                <div
                    className="bg-sand flex flex-wrap items-center gap-3 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper dark:bg-ink"
                    data-test="bulk-link-bar"
                >
                    <span className="font-plex-mono text-[11px] font-semibold uppercase">
                        {selected.length} selected
                    </span>
                    <Select value={target} onValueChange={setTarget}>
                        <SelectTrigger
                            className="w-64"
                            data-test="bulk-link-target"
                        >
                            <SelectValue placeholder="Map to deliverable…" />
                        </SelectTrigger>
                        <SelectContent>
                            {deliverables.map((deliverable) => (
                                <SelectItem
                                    key={deliverable.id}
                                    value={deliverable.id}
                                >
                                    {deliverable.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        size="sm"
                        disabled={target === ''}
                        onClick={mapSelection}
                        className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                        data-test="bulk-link-submit"
                    >
                        Map {selected.length}{' '}
                        {selected.length === 1 ? 'item' : 'items'} →
                    </Button>
                    <InputError
                        message={
                            errors.work_item_ids ?? errors.baseline_item_id
                        }
                    />
                </div>
            )}

            {items.length === 0 ? (
                <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                    No work yet. Synced issues land here after the first sync;
                    in standalone mode, record them manually.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-[13px]">
                        <thead className="border-b-[1.5px] border-ink dark:border-paper">
                            <tr>
                                {canLink && <th className="w-10 px-4 py-2" />}
                                <th className={tableHeading}>Item</th>
                                <th className={tableHeading}>State</th>
                                <th className={tableHeading}>Assignee</th>
                                <th className={tableHeading}>Estimate</th>
                                <th className={tableHeading}>Logged</th>
                                <th className={tableHeading}>Mapped to</th>
                                <th className={tableHeading} />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                            {items.map((item) => (
                                <tr
                                    key={item.id}
                                    data-test={`work-item-row-${item.id}`}
                                >
                                    {canLink && (
                                        <td className="px-4 py-2">
                                            <Checkbox
                                                checked={selected.includes(
                                                    item.id,
                                                )}
                                                onCheckedChange={() =>
                                                    toggle(item.id)
                                                }
                                                aria-label={`Select ${item.title}`}
                                                data-test={`select-work-item-${item.id}`}
                                            />
                                        </td>
                                    )}
                                    <td className="px-4 py-2">
                                        <div className="flex flex-col">
                                            <span className="font-medium">
                                                {item.externalUrl !== null ? (
                                                    <a
                                                        href={item.externalUrl}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="hover:text-rust"
                                                    >
                                                        {item.title}
                                                    </a>
                                                ) : (
                                                    item.title
                                                )}
                                            </span>
                                            <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                                {item.externalKey !== null
                                                    ? `${item.externalKey} · `
                                                    : ''}
                                                {item.sourceLabel}
                                                {item.type !== null
                                                    ? ` · ${item.type}`
                                                    : ''}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-2">
                                        <span
                                            className={cn(
                                                'border px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap uppercase',
                                                stateClasses[item.state],
                                            )}
                                        >
                                            {item.externalStatus ??
                                                item.stateLabel}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2 text-stone dark:text-fog">
                                        {item.assigneeName ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 font-plex-mono">
                                        {item.estimate ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 font-plex-mono">
                                        {item.logged ?? '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {item.link === null ? (
                                            <span
                                                className="border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold whitespace-nowrap text-rust uppercase"
                                                data-test={`unmapped-${item.id}`}
                                            >
                                                Unmapped
                                            </span>
                                        ) : (
                                            <div className="flex flex-col">
                                                <span className="font-medium">
                                                    {item.link.deliverableTitle}
                                                </span>
                                                <span className="text-[11px] text-stone dark:text-fog">
                                                    {item.link.linkedByName !==
                                                        null &&
                                                        `by ${item.link.linkedByName}`}
                                                    {item.link.linkedAt !==
                                                        null &&
                                                        ` · ${item.link.linkedAt}`}
                                                </span>
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        <div className="flex justify-end gap-2 whitespace-nowrap">
                                            {canLink && item.link !== null && (
                                                <button
                                                    type="button"
                                                    onClick={() => unlink(item)}
                                                    className="font-plex-mono text-[11px] font-semibold text-stone uppercase hover:text-rust dark:text-fog"
                                                    data-test={`unlink-${item.id}`}
                                                >
                                                    Unlink
                                                </button>
                                            )}
                                            {canRecord &&
                                                item.source === 'manual' && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setLogging(item)
                                                            }
                                                            className="font-plex-mono text-[11px] font-semibold text-stone uppercase hover:text-rust dark:text-fog"
                                                            data-test={`log-time-${item.id}`}
                                                        >
                                                            Log time
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setEditing(item)
                                                            }
                                                            className="font-plex-mono text-[11px] font-semibold text-stone uppercase hover:text-rust dark:text-fog"
                                                            data-test={`edit-work-item-${item.id}`}
                                                        >
                                                            Edit
                                                        </button>
                                                    </>
                                                )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent>
                    {editing !== null && (
                        <>
                            <DialogTitle>Edit work item</DialogTitle>
                            <DialogDescription>
                                Manual items are updated by hand — synced items
                                mirror their provider.
                            </DialogDescription>
                            <Form
                                {...WorkItemController.update.form(editing.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setEditing(null)}
                                className="flex flex-col gap-4"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <WorkItemFormFields
                                            item={editing}
                                            states={states}
                                            errors={formErrors}
                                        />
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    variant="secondary"
                                                    type="button"
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                data-test="update-work-item-button"
                                            >
                                                Save →
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={logging !== null}
                onOpenChange={(open) => !open && setLogging(null)}
            >
                <DialogContent>
                    {logging !== null && (
                        <>
                            <DialogTitle>
                                Log time on “{logging.title}”
                            </DialogTitle>
                            <DialogDescription>
                                Manual worklogs feed burn the same way synced
                                Jira worklogs do.
                            </DialogDescription>
                            <Form
                                {...WorkItemWorklogController.store.form(
                                    logging.id,
                                )}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setLogging(null)}
                                resetOnSuccess
                                className="flex flex-col gap-4"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="worklog-hours">
                                                    Hours
                                                </Label>
                                                <Input
                                                    id="worklog-hours"
                                                    name="hours"
                                                    type="number"
                                                    step="0.25"
                                                    min="0.25"
                                                    max="24"
                                                    required
                                                    className={`${fieldInput} text-right font-plex-mono`}
                                                />
                                                <InputError
                                                    message={formErrors.hours}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="worklog-date">
                                                    Date
                                                </Label>
                                                <Input
                                                    id="worklog-date"
                                                    name="logged_on"
                                                    type="date"
                                                    required
                                                    className={fieldInput}
                                                />
                                                <InputError
                                                    message={
                                                        formErrors.logged_on
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    variant="secondary"
                                                    type="button"
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                data-test="save-worklog-button"
                                            >
                                                Log time →
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
