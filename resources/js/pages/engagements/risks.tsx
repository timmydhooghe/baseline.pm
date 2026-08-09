import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import RiskController from '@/actions/App/Http/Controllers/RiskController';
import InputError from '@/components/input-error';
import LinkedRecordsField from '@/components/linked-records-field';
import RecordChipList from '@/components/record-chip-list';
import RiskRatingBadge from '@/components/risk-rating-badge';
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
import {
    index as engagements,
    show as engagementShow,
} from '@/routes/engagements';
import { index as risksIndex } from '@/routes/engagements/risks';
import { show as riskShow } from '@/routes/risks';
import type {
    EngagementPositionSummary,
    EngagementStatus,
    GovernanceOptions,
    Money,
    RiskListItem,
} from '@/types';

type Props = {
    engagement: {
        id: string;
        name: string;
        status: EngagementStatus;
        statusLabel: string;
    };
    risks: RiskListItem[];
    summary: {
        live: number;
        escalated: number;
        exposure: Money | null;
        weightedExposure: Money | null;
    };
    options: GovernanceOptions;
    position: EngagementPositionSummary;
    can: { create: boolean };
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const tableHeading =
    'px-4 py-2 font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const fieldClass =
    'rounded-none border-[1.5px] border-ink shadow-none dark:border-paper';

const selectClass =
    'h-10 w-full rounded-none border-[1.5px] border-ink bg-transparent px-3 text-[14px] shadow-none outline-none dark:border-paper';

const ratings = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

/**
 * The risk register (FA-19), ordered worst first. Exposure is cost-derived,
 * so its euro columns are absent for viewers without rate card access — the
 * risks themselves stay visible to everyone.
 */
export default function EngagementRisks({
    engagement,
    risks,
    summary,
    options,
    position,
    can,
}: Props) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Engagements', href: engagements() },
            { title: engagement.name, href: engagementShow(engagement.id) },
            { title: 'Risks', href: risksIndex(engagement.id) },
        ],
        position,
    });

    const [raising, setRaising] = useState(false);
    const showsExposure = summary.weightedExposure !== null;

    const stats = [
        { label: 'Live', value: String(summary.live), warn: false },
        {
            label: 'Escalated',
            value: String(summary.escalated),
            warn: summary.escalated > 0,
        },
        ...(showsExposure
            ? [
                  {
                      label: 'Weighted exposure',
                      value: summary.weightedExposure?.formatted ?? '—',
                      warn: false,
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`${engagement.name} — Risks`} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <div className="font-plex-mono text-[12px] font-semibold text-rust uppercase">
                            Risk register
                        </div>
                        <h1 className="mt-1 font-display text-[28px] font-bold tracking-[-0.02em]">
                            {engagement.name}
                        </h1>
                        <p className="mt-1 max-w-2xl text-[14px] text-stone dark:text-fog">
                            Probability × impact, an owner who carries it, the
                            records it threatens, and exposure as days per role
                            priced from the pinned rate card. High × high and
                            worsening entries surface on Today.
                        </p>
                    </div>
                    <div className="flex gap-3" data-test="risks-summary">
                        {stats.map((stat) => (
                            <div
                                key={stat.label}
                                className={
                                    stat.warn
                                        ? 'border-[1.5px] border-rust px-3 py-2 text-rust'
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

                <div className="border-[1.5px] border-ink dark:border-paper">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                        <span className={sectionLabel}>The register</span>
                        {can.create && (
                            <Dialog open={raising} onOpenChange={setRaising}>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="rounded-none border-[1.5px] border-ink font-semibold shadow-none dark:border-paper"
                                        data-test="raise-risk"
                                    >
                                        Raise a risk
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-xl">
                                    <DialogTitle>Raise a risk</DialogTitle>
                                    <DialogDescription>
                                        Rate it, give it an owner and name what
                                        it threatens. Exposure in euros is
                                        priced from days per role on the risk
                                        itself — never typed.
                                    </DialogDescription>
                                    <Form
                                        {...RiskController.store.form(
                                            engagement.id,
                                        )}
                                        onSuccess={() => setRaising(false)}
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
                                                        placeholder="e.g. Data migration source is unreliable"
                                                        className={fieldClass}
                                                        data-test="risk-title"
                                                    />
                                                    <InputError
                                                        message={errors.title}
                                                    />
                                                </div>
                                                <div className="grid gap-4 sm:grid-cols-3">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="probability">
                                                            Probability
                                                        </Label>
                                                        <select
                                                            id="probability"
                                                            name="probability"
                                                            defaultValue="medium"
                                                            className={
                                                                selectClass
                                                            }
                                                            data-test="risk-probability"
                                                        >
                                                            {ratings.map(
                                                                (rating) => (
                                                                    <option
                                                                        key={
                                                                            rating.value
                                                                        }
                                                                        value={
                                                                            rating.value
                                                                        }
                                                                    >
                                                                        {
                                                                            rating.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="impact">
                                                            Impact
                                                        </Label>
                                                        <select
                                                            id="impact"
                                                            name="impact"
                                                            defaultValue="medium"
                                                            className={
                                                                selectClass
                                                            }
                                                            data-test="risk-impact"
                                                        >
                                                            {ratings.map(
                                                                (rating) => (
                                                                    <option
                                                                        key={
                                                                            rating.value
                                                                        }
                                                                        value={
                                                                            rating.value
                                                                        }
                                                                    >
                                                                        {
                                                                            rating.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="owner_id">
                                                            Owner
                                                        </Label>
                                                        <select
                                                            id="owner_id"
                                                            name="owner_id"
                                                            defaultValue=""
                                                            className={
                                                                selectClass
                                                            }
                                                            data-test="risk-owner"
                                                        >
                                                            <option value="">
                                                                Unassigned
                                                            </option>
                                                            {options.members.map(
                                                                (member) => (
                                                                    <option
                                                                        key={
                                                                            member.value
                                                                        }
                                                                        value={
                                                                            member.value
                                                                        }
                                                                    >
                                                                        {
                                                                            member.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                </div>
                                                <input
                                                    type="hidden"
                                                    name="status"
                                                    value="open"
                                                />
                                                <input
                                                    type="hidden"
                                                    name="visibility"
                                                    value="internal"
                                                />
                                                <LinkedRecordsField
                                                    records={options.records}
                                                    label="Threatens"
                                                    testId="risk-threatens"
                                                />
                                                <InputError
                                                    message={errors.links}
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="rounded-none font-semibold shadow-none"
                                                    data-test="submit-risk"
                                                >
                                                    Add to the register
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>

                    {risks.length === 0 ? (
                        <p className="px-4 py-6 text-[13px] text-stone dark:text-fog">
                            Nothing on the register. A risk nobody wrote down is
                            a risk nobody owns.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-[13px]">
                                <thead className="border-b-[1.5px] border-ink dark:border-paper">
                                    <tr>
                                        <th className={tableHeading}>Risk</th>
                                        <th className={tableHeading}>Rating</th>
                                        <th className={tableHeading}>Status</th>
                                        <th className={tableHeading}>Owner</th>
                                        {showsExposure && (
                                            <th className={tableHeading}>
                                                Weighted exposure
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-ink/15 dark:divide-paper/15">
                                    {risks.map((risk) => (
                                        <tr
                                            key={risk.id}
                                            data-test={`risk-row-${risk.id}`}
                                        >
                                            <td className="px-4 py-2">
                                                <div className="flex flex-col gap-1">
                                                    <Link
                                                        href={riskShow(risk.id)}
                                                        prefetch
                                                        className="font-medium hover:text-rust"
                                                    >
                                                        {risk.title}
                                                    </Link>
                                                    <div className="flex flex-wrap gap-1">
                                                        {risk.escalated && (
                                                            <span className="border border-rust px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold text-rust uppercase">
                                                                High × high
                                                            </span>
                                                        )}
                                                        {risk.worsening && (
                                                            <span className="border border-ochre px-1.5 py-0.5 font-plex-mono text-[10px] font-semibold text-ochre uppercase">
                                                                Worsening
                                                            </span>
                                                        )}
                                                    </div>
                                                    {risk.links.length > 0 && (
                                                        <RecordChipList
                                                            records={risk.links}
                                                        />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2">
                                                <RiskRatingBadge
                                                    probability={
                                                        risk.probability
                                                    }
                                                    impact={risk.impact}
                                                    score={risk.score}
                                                />
                                            </td>
                                            <td className="px-4 py-2 font-plex-mono text-[11px] uppercase">
                                                {risk.statusLabel}
                                            </td>
                                            <td className="px-4 py-2">
                                                {risk.ownerName ?? (
                                                    <span className="text-stone dark:text-fog">
                                                        Unassigned
                                                    </span>
                                                )}
                                            </td>
                                            {showsExposure && (
                                                <td
                                                    className={cn(
                                                        'px-4 py-2 font-plex-mono font-semibold',
                                                    )}
                                                >
                                                    {risk.weightedExposure
                                                        ?.formatted ?? '—'}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {showsExposure && (
                    <p className="text-[12px] text-stone dark:text-fog">
                        Full exposure {summary.exposure?.formatted ?? '—'};{' '}
                        {summary.weightedExposure?.formatted ?? '—'} of it is
                        probability-weighted into the margin risk band. Both
                        derive from the pinned rate card — internal only.
                    </p>
                )}
            </div>
        </>
    );
}
