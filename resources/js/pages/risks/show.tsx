import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import RiskController from '@/actions/App/Http/Controllers/RiskController';
import RiskExposureController from '@/actions/App/Http/Controllers/RiskExposureController';
import InputError from '@/components/input-error';
import LinkedRecordsField from '@/components/linked-records-field';
import OptionalSelect, {
    selectTriggerClass,
} from '@/components/optional-select';
import RecordChipList from '@/components/record-chip-list';
import RiskRatingBadge from '@/components/risk-rating-badge';
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
import { cn } from '@/lib/utils';
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as risksIndex } from '@/routes/engagements/risks';
import { show as riskShow } from '@/routes/risks';
import type {
    EngagementPositionSummary,
    GovernanceOptions,
    RiskExposureLine,
    RiskRevisionView,
    RiskView,
} from '@/types';

type Props = {
    risk: RiskView;
    exposures: RiskExposureLine[];
    revisions: RiskRevisionView[];
    engagement: { id: string; name: string };
    options: GovernanceOptions;
    position: EngagementPositionSummary;
    can: { update: boolean; priceExposure: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const textareaClass =
    'w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 py-2 text-[14px] shadow-none outline-none dark:border-paper';

const ratings = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const statuses = [
    { value: 'open', label: 'Open' },
    { value: 'mitigating', label: 'Mitigating' },
    { value: 'closed', label: 'Closed' },
    { value: 'materialised', label: 'Materialised' },
];

function Panel({
    title,
    children,
    testId,
}: {
    title: string;
    children: React.ReactNode;
    testId?: string;
}) {
    return (
        <div
            className="border-[1.5px] border-ink dark:border-paper"
            data-test={testId}
        >
            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className={sectionLabel}>{title}</span>
            </div>
            <div className="px-4 py-3">{children}</div>
        </div>
    );
}

/**
 * A risk register entry (FA-19): its rating and the history of every
 * re-rating, the records it threatens, its mitigation plan, and the
 * structured exposure the euro figures derive from.
 */
export default function RisksShow({
    risk,
    exposures,
    revisions,
    engagement,
    options,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Risks', href: risksIndex(engagement.id) },
            { title: risk.title, href: riskShow(risk.id) },
        ],
        position,
    });

    const roles = options.roles ?? [];
    const [lines, setLines] = useState(
        exposures.map((exposure) => ({
            roleId: exposure.roleId,
            days: String(exposure.days),
        })),
    );

    return (
        <>
            <Head title={risk.title} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Risk · {risk.statusLabel}
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {risk.title}
                        </h1>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <RiskRatingBadge
                                probability={risk.probability}
                                impact={risk.impact}
                                score={risk.score}
                            />
                            {risk.escalated && (
                                <span className="border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold text-rust uppercase">
                                    High × high
                                </span>
                            )}
                            {risk.worsening && (
                                <span
                                    className="border border-ochre px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold text-ochre uppercase"
                                    data-test="risk-worsening"
                                >
                                    Worsening
                                </span>
                            )}
                            <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                {risk.ownerName ?? 'Unassigned'} ·{' '}
                                {risk.visibilityLabel}
                            </span>
                        </div>
                    </div>
                    {risk.weightedExposure !== null && (
                        <div
                            className="border-[1.5px] border-ink px-3 py-2 dark:border-paper"
                            data-test="risk-exposure-summary"
                        >
                            <div className="font-plex-mono text-[11px] font-semibold text-stone uppercase dark:text-fog">
                                Weighted exposure
                            </div>
                            <div className="font-plex-mono text-[20px] font-semibold">
                                {risk.weightedExposure.formatted}
                            </div>
                            <div className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                of {risk.exposure?.formatted ?? '—'} at risk
                            </div>
                        </div>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="What could happen">
                        <p className="text-[14px] whitespace-pre-line">
                            {risk.description ?? 'No description recorded.'}
                        </p>
                    </Panel>
                    <Panel title="Mitigation">
                        <p className="text-[14px] whitespace-pre-line">
                            {risk.mitigation ?? 'No mitigation plan recorded.'}
                        </p>
                    </Panel>
                </div>

                <Panel title="Threatens" testId="risk-links">
                    <RecordChipList records={risk.links} />
                </Panel>

                {can.update && (
                    <div className="border-[1.5px] border-ink dark:border-paper">
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Reassess the risk
                            </span>
                        </div>
                        <Form
                            {...RiskController.update.form(risk.id)}
                            className="flex flex-col gap-4 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="title">Title</Label>
                                        <Input
                                            id="title"
                                            name="title"
                                            required
                                            defaultValue={risk.title}
                                            className={fieldClass}
                                        />
                                        <InputError message={errors.title} />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="probability">
                                                Probability
                                            </Label>
                                            <Select
                                                name="probability"
                                                defaultValue={risk.probability}
                                            >
                                                <SelectTrigger
                                                    id="probability"
                                                    data-test="reassess-probability"
                                                    className={
                                                        selectTriggerClass
                                                    }
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {ratings.map((rating) => (
                                                        <SelectItem
                                                            key={rating.value}
                                                            value={rating.value}
                                                        >
                                                            {rating.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="impact">
                                                Impact
                                            </Label>
                                            <Select
                                                name="impact"
                                                defaultValue={risk.impact}
                                            >
                                                <SelectTrigger
                                                    id="impact"
                                                    data-test="reassess-impact"
                                                    className={
                                                        selectTriggerClass
                                                    }
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {ratings.map((rating) => (
                                                        <SelectItem
                                                            key={rating.value}
                                                            value={rating.value}
                                                        >
                                                            {rating.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="status">
                                                Status
                                            </Label>
                                            <Select
                                                name="status"
                                                defaultValue={risk.status}
                                            >
                                                <SelectTrigger
                                                    id="status"
                                                    data-test="reassess-status"
                                                    className={
                                                        selectTriggerClass
                                                    }
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {statuses.map((status) => (
                                                        <SelectItem
                                                            key={status.value}
                                                            value={status.value}
                                                        >
                                                            {status.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="owner_id">
                                                Owner
                                            </Label>
                                            <OptionalSelect
                                                name="owner_id"
                                                id="owner_id"
                                                options={options.members}
                                                defaultValue={risk.ownerId}
                                                placeholder="Unassigned"
                                                emptyLabel="Unassigned"
                                                testId="reassess-owner"
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            What could happen
                                        </Label>
                                        <textarea
                                            id="description"
                                            name="description"
                                            rows={3}
                                            defaultValue={
                                                risk.description ?? ''
                                            }
                                            className={textareaClass}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mitigation">
                                            Mitigation plan
                                        </Label>
                                        <textarea
                                            id="mitigation"
                                            name="mitigation"
                                            rows={3}
                                            defaultValue={risk.mitigation ?? ''}
                                            className={textareaClass}
                                            data-test="reassess-mitigation"
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="visibility">
                                                Visibility
                                            </Label>
                                            <Select
                                                name="visibility"
                                                defaultValue={risk.visibility}
                                            >
                                                <SelectTrigger
                                                    id="visibility"
                                                    className={
                                                        selectTriggerClass
                                                    }
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="internal">
                                                        Internal
                                                    </SelectItem>
                                                    <SelectItem value="shared">
                                                        Shared with the customer
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="note">
                                                Why it moved
                                            </Label>
                                            <Input
                                                id="note"
                                                name="note"
                                                placeholder="Recorded on the rating history"
                                                className={fieldClass}
                                                data-test="reassess-note"
                                            />
                                        </div>
                                    </div>
                                    <LinkedRecordsField
                                        records={options.records}
                                        defaultSelected={risk.links}
                                        label="Threatens"
                                    />
                                    <InputError message={errors.links} />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit rounded-none font-semibold shadow-none"
                                        data-test="save-risk"
                                    >
                                        Record the reassessment
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {can.priceExposure && (
                    <div
                        className="border-[1.5px] border-ink dark:border-paper"
                        data-test="risk-exposure-editor"
                    >
                        <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                            <span className={sectionLabel}>
                                Exposure · effort at risk, rate card v
                                {risk.rateCardVersion ?? '—'}
                            </span>
                        </div>
                        <Form
                            {...RiskExposureController.update.form(risk.id)}
                            className="flex flex-col gap-3 px-4 py-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {lines.length === 0 && (
                                        <p className="text-[13px] text-stone dark:text-fog">
                                            No effort at risk priced yet.
                                        </p>
                                    )}
                                    {lines.map((line, index) => (
                                        <div
                                            key={index}
                                            className="flex flex-wrap items-end gap-3"
                                            data-test={`exposure-line-${index}`}
                                        >
                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor={`role-${index}`}
                                                >
                                                    Role
                                                </Label>
                                                <Select
                                                    name={`lines[${index}][rate_card_role_id]`}
                                                    value={line.roleId}
                                                    onValueChange={(next) =>
                                                        setLines((current) =>
                                                            current.map(
                                                                (
                                                                    entry,
                                                                    position,
                                                                ) =>
                                                                    position ===
                                                                    index
                                                                        ? {
                                                                              ...entry,
                                                                              roleId: next,
                                                                          }
                                                                        : entry,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id={`role-${index}`}
                                                        className={cn(
                                                            selectTriggerClass,
                                                            'w-56',
                                                        )}
                                                    >
                                                        <SelectValue placeholder="Choose a role" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {roles.map((role) => (
                                                            <SelectItem
                                                                key={role.value}
                                                                value={
                                                                    role.value
                                                                }
                                                            >
                                                                {role.label} ·{' '}
                                                                {
                                                                    role
                                                                        .costPerDay
                                                                        .formatted
                                                                }
                                                                /day
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor={`days-${index}`}
                                                >
                                                    Days at risk
                                                </Label>
                                                <Input
                                                    id={`days-${index}`}
                                                    name={`lines[${index}][days]`}
                                                    type="number"
                                                    step="0.25"
                                                    min="0.25"
                                                    value={line.days}
                                                    onChange={(event) =>
                                                        setLines((current) =>
                                                            current.map(
                                                                (
                                                                    entry,
                                                                    position,
                                                                ) =>
                                                                    position ===
                                                                    index
                                                                        ? {
                                                                              ...entry,
                                                                              days: event
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : entry,
                                                            ),
                                                        )
                                                    }
                                                    className={cn(
                                                        fieldClass,
                                                        'w-32',
                                                    )}
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setLines((current) =>
                                                        current.filter(
                                                            (_, position) =>
                                                                position !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                                className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    ))}
                                    <InputError message={errors.lines} />
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={roles.length === 0}
                                            onClick={() =>
                                                setLines((current) => [
                                                    ...current,
                                                    {
                                                        roleId:
                                                            roles[0]?.value ??
                                                            '',
                                                        days: '1',
                                                    },
                                                ])
                                            }
                                            className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                            data-test="add-exposure-line"
                                        >
                                            Add a role
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-none font-semibold shadow-none"
                                            data-test="save-exposure"
                                        >
                                            Price the exposure
                                        </Button>
                                    </div>
                                    <p className="text-[12px] text-stone dark:text-fog">
                                        Days × the pinned cost rate, weighted by
                                        probability into the margin risk band.
                                        Internal only — exposure never reaches
                                        the portal.
                                    </p>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {exposures.length > 0 && (
                    <Panel title="Priced exposure" testId="risk-exposures">
                        <ul className="flex flex-col gap-1 text-[13px]">
                            {exposures.map((exposure) => (
                                <li
                                    key={exposure.id}
                                    className="flex flex-wrap gap-2 font-plex-mono"
                                >
                                    <span className="font-semibold">
                                        {exposure.roleName}
                                    </span>
                                    <span className="text-stone dark:text-fog">
                                        {exposure.days} days ×{' '}
                                        {exposure.costPerDay.formatted}
                                    </span>
                                    <span className="font-semibold">
                                        = {exposure.cost.formatted}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                <Panel title="Rating history" testId="risk-revisions">
                    <ul className="flex flex-col gap-2 text-[13px]">
                        {revisions.map((revision) => (
                            <li
                                key={revision.id}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <RiskRatingBadge
                                    probability={revision.probability}
                                    impact={revision.impact}
                                    score={revision.score}
                                />
                                <span className="font-plex-mono text-[11px] text-stone uppercase dark:text-fog">
                                    {revision.statusLabel} ·{' '}
                                    {revision.recordedAt}
                                    {revision.actorName !== null &&
                                        ` · ${revision.actorName}`}
                                    {revision.weightedExposure !== null &&
                                        ` · ${revision.weightedExposure.formatted} weighted`}
                                </span>
                                {revision.note !== null && (
                                    <span className="text-stone dark:text-fog">
                                        “{revision.note}”
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </Panel>
            </div>
        </>
    );
}
