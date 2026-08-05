<?php

namespace App\Http\Controllers;

use App\Enums\BaselineItemType;
use App\Enums\EstimateUnit;
use App\Models\BaselineItem;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkItem;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TriageController extends Controller
{
    /**
     * The triage inbox (FA-9): every unmapped, untriaged work item with the
     * evidence a classification needs — age, logged time, origin context,
     * effort, rate-card-derived cost, potential price, timeline impact and
     * the nearest deliverable — plus the decisions already on record and the
     * unbilled risk the unresolved queue rolls up to (FA-10).
     */
    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $baseline = $engagement->currentBaseline();
        $rates = $baseline?->blendedDayRates();

        $deliverables = $baseline === null
            ? collect()
            : $baseline->items->where('type', BaselineItemType::Deliverable)->values();

        $nearestMilestone = $baseline?->items
            ->filter(fn (BaselineItem $item): bool => $item->type === BaselineItemType::Milestone
                && $item->baseline_date !== null
                && $item->baseline_date->gte(today()))
            ->sortBy('baseline_date')
            ->first();

        $inbox = $engagement->driftWorkItems()
            ->with('worklogs')
            ->orderBy('created_at')
            ->get();

        $triaged = $engagement->workItems()
            ->whereNotNull('triage_status')
            ->with(['worklogs', 'triagedBy', 'link.baselineItem', 'changeRequest'])
            ->orderByDesc('triaged_at')
            ->get();

        return Inertia::render('engagements/triage', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'inbox' => $inbox
                ->map(fn (WorkItem $item): array => $this->inboxItemViewModel($item, $rates, $deliverables, $nearestMilestone))
                ->values(),
            'triaged' => $triaged
                ->map(fn (WorkItem $item): array => $this->triagedItemViewModel($item))
                ->values(),
            'deliverables' => $deliverables
                ->map(fn (BaselineItem $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                ])
                ->values(),
            'nearestMilestone' => $nearestMilestone === null ? null : [
                'title' => $nearestMilestone->title,
                'date' => $nearestMilestone->baseline_date?->toFormattedDateString(),
                'daysUntil' => $nearestMilestone->baseline_date === null ? null : (int) round(today()->diffInDays($nearestMilestone->baseline_date)),
            ],
            'pricing' => [
                'available' => $rates !== null,
                'baselineVersion' => $baseline?->version,
                'rateCardVersion' => $baseline?->rateCardVersion?->version,
                'costPerDay' => $rates === null ? null : $rates['cost']->toArray(),
                'sellPerDay' => $rates === null ? null : $rates['sell']->toArray(),
                'hoursPerDay' => EstimateUnit::HOURS_PER_DAY,
            ],
            'position' => $engagement->positionSummary(),
            'can' => [
                'triage' => $user->can('triageAny', [WorkItem::class, $engagement]),
            ],
        ]);
    }

    /**
     * @param  array{cost: Money, sell: Money}|null  $rates
     * @param  Collection<int, BaselineItem>  $deliverables
     * @return array<string, mixed>
     */
    private function inboxItemViewModel(
        WorkItem $item,
        ?array $rates,
        Collection $deliverables,
        ?BaselineItem $nearestMilestone,
    ): array {
        $priced = $item->priceEffort($rates);
        $startedAt = $item->workStartedAt();
        $loggedSeconds = $item->loggedSeconds();
        $effortDays = $priced['days'] === null ? null : round($priced['days'], 2);

        return [
            'id' => $item->id,
            'title' => $item->title,
            'externalKey' => $item->external_key,
            'externalUrl' => $item->external_url,
            'sourceLabel' => $item->source->label(),
            'type' => $item->type,
            'assigneeName' => $item->assignee_name,
            'state' => $item->state->value,
            'stateLabel' => $item->state->label(),
            'externalStatus' => $item->external_status,
            'ageDays' => (int) round($item->created_at?->diffInDays(now()) ?? 0),
            'firstSeen' => $item->created_at?->toFormattedDateString(),
            'estimate' => $item->estimate_value !== null && $item->estimate_unit !== null
                ? $item->estimate_unit->format($item->estimate_value)
                : null,
            'logged' => $loggedSeconds > 0 ? round($loggedSeconds / 3600, 1).'h' : null,
            'effortDays' => $effortDays,
            'cost' => $priced['cost']?->toArray(),
            'price' => $priced['price']?->toArray(),
            'workStartedAt' => $startedAt?->toFormattedDateString(),
            'breachRisk' => $startedAt !== null,
            'suggestedDeliverable' => $this->nearestDeliverable($item, $deliverables),
            'timelineImpact' => $nearestMilestone === null || $effortDays === null ? null : [
                'milestone' => $nearestMilestone->title,
                'daysUntil' => $nearestMilestone->baseline_date === null ? null : (int) round(today()->diffInDays($nearestMilestone->baseline_date)),
                'effortDays' => $effortDays,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function triagedItemViewModel(WorkItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'externalKey' => $item->external_key,
            'sourceLabel' => $item->source->label(),
            'classification' => $item->triage_status?->value,
            'classificationLabel' => $item->triage_status?->label(),
            'triagedByName' => $item->triagedBy?->name,
            'triagedAt' => $item->triaged_at?->toFormattedDateString(),
            'note' => $item->triage_note,
            'deliverableTitle' => $item->link?->baselineItem->title,
            'changeRequest' => $item->changeRequest === null ? null : [
                'id' => $item->changeRequest->id,
                'title' => $item->changeRequest->title,
                'statusLabel' => $item->changeRequest->status->label(),
                'breachRisk' => $item->changeRequest->flagsContractualBreach(),
            ],
        ];
    }

    /**
     * Deliverables carry no dates, so "nearest" is textual: the deliverable
     * whose title reads closest to the work item's. A hint that pre-selects
     * the existing-scope target — never an automatic link.
     *
     * @param  Collection<int, BaselineItem>  $deliverables
     * @return array{id: string, title: string}|null
     */
    private function nearestDeliverable(WorkItem $item, Collection $deliverables): ?array
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($deliverables as $deliverable) {
            similar_text(mb_strtolower($item->title), mb_strtolower($deliverable->title), $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $deliverable;
            }
        }

        return $best === null ? null : ['id' => $best->id, 'title' => $best->title];
    }
}
