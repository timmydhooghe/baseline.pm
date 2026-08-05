import { formatBytes } from '@/components/baseline-step-contract';
import type { BaselineItemType, BaselineView, SelectOption } from '@/types';

type Props = {
    baseline: BaselineView;
    commercialModels: SelectOption[];
    executionModes: SelectOption[];
};

const sectionLabel =
    'font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog';

const TYPE_LABELS: Record<BaselineItemType, string> = {
    deliverable: 'Deliverables',
    milestone: 'Milestones',
    assumption: 'Assumptions',
    exclusion: 'Exclusions',
    responsibility: 'Customer responsibilities',
};

function optionLabel(options: SelectOption[], value: string) {
    return options.find((option) => option.value === value)?.label ?? value;
}

/**
 * The frozen recap of a baseline: shown on the submit step and as the
 * read-only view once the baseline left draft. Commercials stay internal —
 * the customer reviews a snapshot with cost and margin stripped.
 */
export default function BaselineReview({
    baseline,
    commercialModels,
    executionModes,
}: Props) {
    const deliverableValueSum = baseline.items
        .filter((item) => item.type === 'deliverable')
        .reduce((sum, item) => sum + (item.value?.amount ?? 0), 0);

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-2 dark:border-paper">
                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 border-ink/20 px-4 py-4 text-[13px] sm:border-r dark:border-paper/20">
                    <dt className={sectionLabel}>Model</dt>
                    <dd>
                        {optionLabel(
                            commercialModels,
                            baseline.commercialModel,
                        )}
                    </dd>
                    <dt className={sectionLabel}>Value</dt>
                    <dd className="font-plex-mono">
                        {baseline.contractValue.formatted}
                    </dd>
                    <dt className={sectionLabel}>Dates</dt>
                    <dd className="font-plex-mono">
                        {baseline.startDate} → {baseline.endDate}
                    </dd>
                    <dt className={sectionLabel}>Execution</dt>
                    <dd>
                        {optionLabel(executionModes, baseline.executionMode)}
                    </dd>
                </dl>
                <div className="px-4 py-4">
                    <div className={sectionLabel}>
                        Contract · {baseline.documents.length}{' '}
                        {baseline.documents.length === 1
                            ? 'document'
                            : 'documents'}
                    </div>
                    <ul className="mt-2 flex flex-col gap-1 text-[13px]">
                        {baseline.documents.map((document) => (
                            <li key={document.id}>
                                {document.filename}{' '}
                                <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                    {formatBytes(document.sizeBytes)}
                                </span>
                            </li>
                        ))}
                        {baseline.documents.length === 0 && (
                            <li className="text-stone dark:text-fog">
                                No documents attached.
                            </li>
                        )}
                    </ul>
                </div>
            </div>

            {(Object.keys(TYPE_LABELS) as BaselineItemType[]).map((type) => {
                const items = baseline.items.filter(
                    (item) => item.type === type,
                );

                if (items.length === 0) {
                    return null;
                }

                return (
                    <div
                        key={type}
                        className="border-[1.5px] border-ink dark:border-paper"
                    >
                        <div className="border-b-[1.5px] border-ink px-4 py-2 dark:border-paper">
                            <span className={sectionLabel}>
                                {TYPE_LABELS[type]} · {items.length}
                            </span>
                        </div>
                        <ul className="divide-y divide-ink/20 text-[13px] dark:divide-paper/20">
                            {items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {item.title}
                                        </span>{' '}
                                        <span className="font-plex-mono text-[11px] text-stone dark:text-fog">
                                            {item.clauseReference}
                                        </span>
                                    </span>
                                    <span className="font-plex-mono text-[12px] text-stone dark:text-fog">
                                        {item.type === 'deliverable' &&
                                            [
                                                item.owner?.name,
                                                item.value?.formatted,
                                                `${item.acceptanceCriteria.length} criteria`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        {item.type === 'milestone' &&
                                            [
                                                item.baselineDate,
                                                item.paymentTrigger,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                );
            })}

            <div className="grid gap-0 border-[1.5px] border-ink sm:grid-cols-3 dark:border-paper">
                <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                    <div className={sectionLabel}>Deliverable values</div>
                    <div className="mt-1 font-plex-mono text-[16px] font-bold">
                        {baseline.contractValue.currency === 'EUR' && '€ '}
                        {(deliverableValueSum / 100).toLocaleString('de-DE', {
                            minimumFractionDigits: 2,
                        })}
                    </div>
                </div>
                <div className="border-ink/20 px-4 py-3 sm:border-r dark:border-paper/20">
                    <div className={sectionLabel}>Cost budget — internal</div>
                    <div className="mt-1 font-plex-mono text-[16px] font-bold">
                        {baseline.totals.costBudget.formatted}
                    </div>
                </div>
                <div className="px-4 py-3">
                    <div className={sectionLabel}>
                        Planned margin — internal
                    </div>
                    <div className="mt-1 font-plex-mono text-[16px] font-bold">
                        {baseline.totals.plannedMargin.formatted}
                    </div>
                </div>
            </div>
        </div>
    );
}
