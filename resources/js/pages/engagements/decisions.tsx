import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import DecisionController from '@/actions/App/Http/Controllers/DecisionController';
import DecisionTranscriptController from '@/actions/App/Http/Controllers/DecisionTranscriptController';
import InputError from '@/components/input-error';
import LinkedRecordsField from '@/components/linked-records-field';
import RecordChipList from '@/components/record-chip-list';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { show as decisionShow } from '@/routes/decisions';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as decisionsIndex } from '@/routes/engagements/decisions';
import type {
    DecisionListItem,
    EngagementPositionSummary,
    EngagementStatus,
    RecordChip,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    decisions: DecisionListItem[];
    counts: {
        total: number;
        drafts: number;
        shared: number;
        awaitingAcknowledgement: number;
    };
    filters: { q: string };
    options: { records: RecordChip[] };
    position: EngagementPositionSummary;
    can: { create: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const textareaClass =
    'w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[14px] shadow-none outline-none dark:border-paper';

function StatusBadge({ decision }: { decision: DecisionListItem }) {
    return (
        <span
            className={cn(
                'inline-block border-[1.5px] px-2 py-0.5 font-plex-mono text-[11px] font-semibold whitespace-nowrap uppercase',
                decision.status === 'confirmed' &&
                    'border-moss bg-moss text-paper',
                decision.status === 'superseded' &&
                    'border-ink/40 text-stone dark:border-paper/40 dark:text-fog',
                decision.status === 'draft' && 'border-ink bg-sun text-ink',
            )}
        >
            {decision.statusLabel}
        </span>
    );
}

/**
 * The decision ledger (FA-18). Reading it answers "why is it like this?", so
 * search sits at the top and every row carries what was decided, what it
 * cost and which records it touched.
 */
export default function EngagementDecisions({
    engagement,
    decisions,
    counts,
    filters,
    options,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Decisions', href: decisionsIndex(engagement.id) },
        ],
        position,
    });

    const [recording, setRecording] = useState(false);
    const [proposing, setProposing] = useState(false);
    const [search, setSearch] = useState(filters.q);

    const stats = [
        { label: 'On the ledger', value: String(counts.total), warn: false },
        {
            label: 'Drafts',
            value: String(counts.drafts),
            warn: counts.drafts > 0,
        },
        {
            label: 'Awaiting acknowledgment',
            value: String(counts.awaitingAcknowledgement),
            warn: counts.awaitingAcknowledgement > 0,
        },
    ];

    return (
        <>
            <Head title={`${engagement.name} — Decisions`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Decision ledger
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Why the engagement is the way it is: the context,
                            the alternatives that lost, who was in the room and
                            what it cost. Confirmed records are never rewritten
                            — a change of mind supersedes them.
                        </p>
                    </div>
                    <div className="flex gap-3" data-test="decisions-summary">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={
                                    stat.warn
                                        ? 'border-[1.5px] border-ochre px-3 py-2 text-ochre'
                                        : 'border-[1.5px] border-ink px-3 py-2 dark:border-paper'
                                }
                            >
                                <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                    {stat.label}
                                </div>
                                <div className="font-plex-mono text-[20px] font-semibold">
                                    {stat.value}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        className="flex items-center gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                decisionsIndex(engagement.id).url,
                                search === '' ? {} : { q: search },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Why was SSO excluded?"
                            aria-label="Search the decision ledger"
                            className={cn(fieldClass, 'w-72')}
                            data-test="decision-search"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                        >
                            Search
                        </Button>
                    </form>

                    {can.create && (
                        <div className="flex gap-2">
                            <Dialog
                                open={proposing}
                                onOpenChange={setProposing}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="open-transcript-dialog"
                                    >
                                        Propose from transcript
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-2xl">
                                    <DialogTitle>
                                        Propose decisions from a transcript
                                    </DialogTitle>
                                    <DialogDescription>
                                        Paste the meeting transcript. Anything
                                        that reads like a closed decision is
                                        proposed as a draft with the excerpt it
                                        came from — nothing reaches the ledger
                                        until you confirm it.
                                    </DialogDescription>
                                    <Form
                                        {...DecisionTranscriptController.store.form(
                                            engagement.id,
                                        )}
                                        onSuccess={() => setProposing(false)}
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="transcript">
                                                        Transcript
                                                    </Label>
                                                    <textarea
                                                        id="transcript"
                                                        name="transcript"
                                                        required
                                                        rows={12}
                                                        placeholder={
                                                            'Anna: We looked at SSO again.\nTom: Three days of work.\nDecision: SSO is excluded from phase 1.'
                                                        }
                                                        className={
                                                            textareaClass
                                                        }
                                                        data-test="transcript-input"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.transcript
                                                        }
                                                    />
                                                </div>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="rounded-none font-semibold shadow-none"
                                                    data-test="submit-transcript"
                                                >
                                                    Propose drafts
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>

                            <Dialog
                                open={recording}
                                onOpenChange={setRecording}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="record-decision"
                                    >
                                        Record a decision
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-xl">
                                    <DialogTitle>
                                        Record a decision draft
                                    </DialogTitle>
                                    <DialogDescription>
                                        Drafts carry no governance weight. The
                                        outcome and the date it was taken are
                                        required when you confirm it.
                                    </DialogDescription>
                                    <Form
                                        {...DecisionController.store.form(
                                            engagement.id,
                                        )}
                                        onSuccess={() => setRecording(false)}
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="title">
                                                        Title
                                                    </Label>
                                                    <Input
                                                        id="title"
                                                        name="title"
                                                        required
                                                        placeholder="e.g. SSO excluded from phase 1"
                                                        className={fieldClass}
                                                        data-test="decision-title"
                                                    />
                                                    <InputError
                                                        message={errors.title}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="context">
                                                        Context
                                                    </Label>
                                                    <textarea
                                                        id="context"
                                                        name="context"
                                                        required
                                                        rows={4}
                                                        placeholder="What was on the table, and why it mattered."
                                                        className={
                                                            textareaClass
                                                        }
                                                        data-test="decision-context"
                                                    />
                                                    <InputError
                                                        message={errors.context}
                                                    />
                                                </div>
                                                <input
                                                    type="hidden"
                                                    name="visibility"
                                                    value="internal"
                                                />
                                                <LinkedRecordsField
                                                    records={options.records}
                                                    hint="Records can also be linked from the decision itself."
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="rounded-none font-semibold shadow-none"
                                                    data-test="submit-decision"
                                                >
                                                    Record draft
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    )}
                </div>

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>
                            {filters.q === ''
                                ? 'The ledger'
                                : `Matching “${filters.q}”`}
                        </span>
                    </div>
                    {decisions.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            {filters.q === ''
                                ? 'No decisions recorded yet. Record one by hand, or paste a meeting transcript and confirm what it proposes.'
                                : 'No decision matches that search.'}
                        </p>
                    ) : (
                        <ul className="divide-y divide-ink/15 dark:divide-paper/15">
                            {decisions.map((decision) => (
                                <li
                                    key={decision.id}
                                    className="flex flex-col gap-2 px-4 py-3"
                                    data-test={`decision-row-${decision.id}`}
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link
                                            href={decisionShow(decision.id)}
                                            prefetch
                                            className="text-[14px] font-medium hover:text-rust"
                                        >
                                            {decision.title}
                                        </Link>
                                        <StatusBadge decision={decision} />
                                        {decision.visibility === 'shared' && (
                                            <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase dark:border-paper/40">
                                                Shared
                                                {decision.acknowledgedAt !==
                                                    null && ' · acknowledged'}
                                            </span>
                                        )}
                                        {decision.source === 'transcript' && (
                                            <span className="border border-ink/40 px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold uppercase dark:border-paper/40">
                                                From transcript
                                            </span>
                                        )}
                                    </div>
                                    {decision.decision !== null && (
                                        <p className="text-[13px]">
                                            {decision.decision}
                                        </p>
                                    )}
                                    <div className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                        {decision.decidedOn ?? 'No date yet'}
                                        {decision.decidedByName !== null &&
                                            ` · ${decision.decidedByName}`}
                                        {decision.participantCount > 0 &&
                                            ` · ${decision.participantCount} participants`}
                                        {decision.impactTimelineDays !== null &&
                                            ` · ${decision.impactTimelineDays > 0 ? '+' : ''}${decision.impactTimelineDays} days`}
                                    </div>
                                    {decision.supersedesTitle !== null && (
                                        <div className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                            Supersedes{' '}
                                            {decision.supersedesTitle}
                                        </div>
                                    )}
                                    {decision.supersededByTitle !== null && (
                                        <div className="font-plex-mono text-[11px] text-ochre">
                                            Superseded by{' '}
                                            {decision.supersededByTitle}
                                        </div>
                                    )}
                                    {decision.links.length > 0 && (
                                        <RecordChipList
                                            records={decision.links}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
