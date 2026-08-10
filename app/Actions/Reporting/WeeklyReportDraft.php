<?php

namespace App\Actions\Reporting;

use App\Actions\Governance\GovernanceRecordLabel;
use App\Enums\ChangeRequestStatus;
use App\Enums\DecisionStatus;
use App\Enums\DependencyStatus;
use App\Enums\RecordVisibility;
use App\Models\Baseline;
use App\Models\BurnWeek;
use App\Models\ChangeRequest;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\RiskRevision;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The weekly report, drafted from evidence (FA-26): what moved, what changed,
 * what is owed and by whom — every line carrying the record it derives from,
 * so nothing in the report is an assertion. Drafts are never stored; they are
 * derived fresh from the ledgers each time one is read, and only publishing
 * freezes the result.
 *
 * The customer variant is built without cost or margin and without
 * internal-visibility records — stripped structurally, never merely blanked
 * (FA-27). When the previous week's report was published, each deliverable
 * line carries what that frozen report said about it, so the draft reads as
 * a diff against what the customer was last told rather than a restatement.
 */
class WeeklyReportDraft
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Engagement $engagement, CarbonImmutable $weekStart, bool $internal): array
    {
        $weekStart = BurnWeek::startOfWeekFor($weekStart);
        $weekEnd = $weekStart->addWeek();
        $approved = $engagement->approvedBaseline();

        $previous = $engagement->reports()
            ->whereDate('week_start', $weekStart->subWeek()->toDateString())
            ->first();
        $previousPayload = ($internal ? $previous?->reviewSnapshot : $previous?->customerSnapshot)?->payload;

        $payload = [
            'kind' => $internal ? 'internal_report' : 'customer_report',
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekStart->addDays(6)->toDateString(),
                'label' => BurnWeek::labelFor($weekStart),
            ],
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
            ],
            'customer' => [
                'id' => $engagement->customer->id,
                'name' => $engagement->customer->name,
            ],
            'baseline' => $approved === null ? null : [
                'version' => $approved->version,
                'contract_value' => $approved->contract_value->toArray(),
            ],
            'previous' => $previous === null ? null : [
                'report_id' => $previous->id,
                'week_start' => BurnWeek::startOfWeekFor($previous->week_start)->toDateString(),
                'week_label' => $previous->label(),
            ],
            'moved' => $this->moved($engagement, $previousPayload),
            'changed' => $this->changed($engagement, $weekStart, $weekEnd, $internal),
            'owed' => $this->owed($engagement, $internal),
        ];

        if (! $internal) {
            return $payload;
        }

        $payload['commercials'] = $this->commercials($engagement, $weekStart, $previousPayload);

        return $payload;
    }

    /**
     * What moved: every deliverable's current state, each line carrying what
     * the previous published report said about it — the diff the reader
     * actually wants is "since we last told you", not "since Monday".
     *
     * @param  array<string, mixed>|null  $previousPayload
     * @return list<array<string, mixed>>
     */
    private function moved(Engagement $engagement, ?array $previousPayload): array
    {
        $before = [];

        foreach (($previousPayload['moved'] ?? []) as $line) {
            if (is_array($line) && isset($line['record']['id'])) {
                $before[$line['record']['id']] = $line;
            }
        }

        return array_values($engagement->deliverables()
            ->with(['baselineItem', 'milestoneItem'])
            ->get()
            ->sortBy(fn (Deliverable $deliverable): int => $deliverable->baselineItem->position)
            ->map(function (Deliverable $deliverable) use ($before): array {
                $previous = $before[$deliverable->id] ?? null;

                return [
                    'record' => GovernanceRecordLabel::chip($deliverable),
                    'status' => $deliverable->status->value,
                    'status_label' => $deliverable->status->label(),
                    'progress' => $deliverable->progress,
                    'value' => $deliverable->baselineItem->value?->toArray(),
                    'forecast_date' => $deliverable->forecast_date?->toDateString(),
                    'milestone' => $deliverable->milestoneItem?->title,
                    'accepted_at' => $deliverable->accepted_at?->toDateString(),
                    'previous' => $previous === null ? null : [
                        'progress' => $previous['progress'] ?? null,
                        'status' => $previous['status'] ?? null,
                        'status_label' => $previous['status_label'] ?? null,
                    ],
                ];
            })
            ->all());
    }

    /**
     * What changed: the week's governance events in the order they happened —
     * baselines approved, change requests submitted and decided, deliverables
     * submitted and signed, decisions confirmed, risks raised or re-rated,
     * dependencies settled. The customer variant carries shared-visibility
     * records only.
     *
     * @return list<array<string, mixed>>
     */
    private function changed(Engagement $engagement, CarbonImmutable $weekStart, CarbonImmutable $weekEnd, bool $internal): array
    {
        $within = fn (?DateTimeInterface $moment): bool => $moment !== null
            && ! $weekStart->greaterThan($moment)
            && $weekEnd->greaterThan($moment);

        $events = [];

        foreach ($engagement->baselines()->get() as $baseline) {
            if ($baseline->approved_at !== null && $within($baseline->approved_at)) {
                $events[] = $this->event($baseline, 'baseline.approved', __('Baseline v:version approved', ['version' => $baseline->version]), $baseline->approved_at, __('Contract value :value.', ['value' => $baseline->contract_value->format()]));
            }
        }

        foreach ($engagement->changeRequests()->get() as $changeRequest) {
            if ($changeRequest->submitted_at !== null && $within($changeRequest->submitted_at)) {
                $events[] = $this->event($changeRequest, 'change_request.submitted', __('Change request submitted for approval'), $changeRequest->submitted_at, __('Proposed at :price — respond by :date.', [
                    'price' => $changeRequest->customer_price?->format() ?? '—',
                    'date' => $changeRequest->respond_by?->toFormattedDateString() ?? '—',
                ]));
            }

            if ($changeRequest->decided_at !== null && $within($changeRequest->decided_at)) {
                $events[] = $changeRequest->status === ChangeRequestStatus::Approved
                    ? $this->event($changeRequest, 'change_request.approved', __('Change request approved'), $changeRequest->decided_at, __('Approved at :price.', ['price' => $changeRequest->customer_price?->format() ?? '—']))
                    : $this->event($changeRequest, 'change_request.rejected', __('Change request rejected'), $changeRequest->decided_at, null);
            }
        }

        foreach ($engagement->deliverables()->with('baselineItem')->get() as $deliverable) {
            if ($deliverable->submitted_at !== null && $within($deliverable->submitted_at)) {
                $events[] = $this->event($deliverable, 'deliverable.submitted', __('Deliverable submitted for acceptance'), $deliverable->submitted_at, __('Respond by :date.', ['date' => $deliverable->respond_by?->toFormattedDateString() ?? '—']));
            }

            if ($deliverable->accepted_at !== null && $within($deliverable->accepted_at)) {
                $events[] = $this->event($deliverable, 'deliverable.accepted', __('Deliverable accepted'), $deliverable->accepted_at, __('Signed at :value.', ['value' => $deliverable->accepted_value?->format() ?? '—']));
            }
        }

        $decisions = $engagement->decisions()->where('status', DecisionStatus::Confirmed)->get();

        foreach ($decisions as $decision) {
            if (! $internal && ! $decision->visibility->isShared()) {
                continue;
            }

            if ($decision->decided_on !== null && $within($decision->decided_on)) {
                $events[] = $this->event($decision, 'decision.confirmed', __('Decision recorded'), $decision->decided_on, null);
            }
        }

        foreach ($engagement->risks()->with('revisions')->get() as $risk) {
            if (! $internal && ! $risk->visibility->isShared()) {
                continue;
            }

            $events = [...$events, ...$this->riskEvents($risk, $within)];
        }

        foreach ($engagement->dependencies()->get() as $dependency) {
            if (! $internal && ! $dependency->visibility->isShared()) {
                continue;
            }

            if ($dependency->settled_on !== null && $within($dependency->settled_on)) {
                $late = $dependency->delayDays();
                $events[] = $this->event(
                    $dependency,
                    $dependency->status === DependencyStatus::Waived ? 'dependency.waived' : 'dependency.received',
                    $dependency->status === DependencyStatus::Waived ? __('Dependency waived') : __('Dependency received'),
                    $dependency->settled_on,
                    $late > 0 ? trans_choice('{1}Settled :count day late.|[2,*]Settled :count days late.', $late, ['count' => $late]) : null,
                );
            }
        }

        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $events;
    }

    /**
     * The register events a risk produced this week: raised, or re-rated by
     * a revision that changed its rating. The opening revision is the
     * raising, not a re-rating.
     *
     * @param  callable(DateTimeInterface|null): bool  $within
     * @return list<array<string, mixed>>
     */
    private function riskEvents(Risk $risk, callable $within): array
    {
        $rating = fn (RiskRevision|Risk $rated): string => $rated->probability->label().' × '.$rated->impact->label();

        if ($risk->created_at !== null && $within($risk->created_at)) {
            return [$this->event($risk, 'risk.raised', __('Risk raised'), $risk->created_at, $rating($risk))];
        }

        $revisions = $risk->revisions->sortBy('created_at')->values();
        $inWindow = $revisions->filter(fn (RiskRevision $revision): bool => $within($revision->created_at));

        if ($inWindow->isEmpty()) {
            return [];
        }

        $last = $inWindow->last();
        $before = $revisions
            ->filter(fn (RiskRevision $revision): bool => $revision->created_at->lessThan($inWindow->first()->created_at))
            ->last();

        if ($before === null || $rating($before) === $rating($last)) {
            return [];
        }

        return [$this->event($risk, 'risk.rerated', __('Risk re-rated'), $last->created_at, $rating($before).' → '.$rating($last))];
    }

    /**
     * What is owed and by whom: every outstanding dependency with its
     * responsible party and how late it is. The customer variant carries
     * shared-visibility records only.
     *
     * @return list<array<string, mixed>>
     */
    private function owed(Engagement $engagement, bool $internal): array
    {
        return array_values($engagement->dependencies()
            ->whereIn('status', [DependencyStatus::Pending, DependencyStatus::Requested, DependencyStatus::Escalated])
            ->when(! $internal, fn ($query) => $query->where('visibility', RecordVisibility::Shared))
            ->with('responsibleStakeholder')
            ->orderBy('required_on')
            ->get()
            ->map(fn (Dependency $dependency): array => [
                'record' => GovernanceRecordLabel::chip($dependency),
                'party' => $dependency->party->value,
                'party_label' => $dependency->party->label(),
                'responsible' => $dependency->responsibleName(),
                'required_on' => $dependency->required_on->toDateString(),
                'late' => $dependency->isLate(),
                'delay_days' => $dependency->delayDays(),
                'status' => $dependency->status->value,
                'status_label' => $dependency->status->label(),
            ])
            ->all());
    }

    /**
     * The internal commercial block: the position rail as it stands, the
     * week's recorded burn, and what the previous internal report said the
     * margin and burn were — so the report can say how the money moved, not
     * just where it is. Kept in the rail's own shape so the reader renders
     * the same waterfall it renders everywhere else.
     *
     * @param  array<string, mixed>|null  $previousPayload
     * @return array<string, mixed>
     */
    private function commercials(Engagement $engagement, CarbonImmutable $weekStart, ?array $previousPayload): array
    {
        $burnWeek = $engagement->currentBurnWeeks()
            ->whereDate('week_start', $weekStart->toDateString())
            ->with('entries')
            ->first();

        $previousPosition = $previousPayload['commercials']['position'] ?? null;

        return [
            'position' => $engagement->positionSummary(true),
            'burn_week' => $burnWeek === null ? null : [
                'cost' => $burnWeek->cost->toArray(),
                'days' => round($burnWeek->days(), 2),
            ],
            'previous' => ! is_array($previousPosition) ? null : [
                'margin_percent' => $previousPosition['margin']['percent'] ?? null,
                'recorded_burn' => $previousPosition['burn']['recorded'] ?? null,
            ],
        ];
    }

    /**
     * One line of the week's story, carrying the record it derives from.
     *
     * @return array<string, mixed>
     */
    private function event(Baseline|ChangeRequest|Deliverable|Decision|Risk|Dependency $record, string $event, string $label, DateTimeInterface $date, ?string $detail): array
    {
        return [
            'record' => $record instanceof Baseline
                ? ['type' => $record->getMorphClass(), 'type_label' => __('Baseline'), 'id' => $record->id, 'title' => __('Baseline v:version', ['version' => $record->version])]
                : GovernanceRecordLabel::chip($record),
            'event' => $event,
            'event_label' => $label,
            'date' => CarbonImmutable::parse($date)->toDateString(),
            'detail' => $detail,
        ];
    }
}
