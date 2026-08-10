<?php

namespace App\Http\Controllers;

use App\Actions\Money\WeeklyBurnSuggestion;
use App\Http\Requests\Burn\RecordBurnWeekRequest;
use App\Models\BurnEntry;
use App\Models\BurnWeek;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly burn entry (FA-16). The week arrives prefilled — logged time first,
 * a progress-derived suggestion for the profiles that logged none, everything
 * editable — and recording freezes it as an immutable snapshot that moves
 * cost-to-date, forecast-at-completion, margin and budget %.
 *
 * Corrections are new recordings: the earlier week stays on the ledger,
 * marked superseded by the one that replaced it, so the trail shows both what
 * was believed and what replaced it.
 *
 * Burn is cost, and cost is internal (FA-27) — the whole page sits behind the
 * roles that may read the rate card.
 */
class BurnController extends Controller
{
    public function index(Request $request, Engagement $engagement, WeeklyBurnSuggestion $suggestion): Response
    {
        Gate::authorize('view', $engagement);
        Gate::authorize('viewAny', BurnWeek::class);

        $user = $request->user();
        $unrecorded = $engagement->unrecordedBurnWeeks();
        $week = $this->selectedWeek($request, $unrecorded);
        $forecast = $engagement->marginForecast(withAttribution: false);

        return Inertia::render('engagements/burn', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'week' => $suggestion($engagement, $week),
            'weeks' => $this->ledger($engagement),
            'unrecorded' => array_map(fn (CarbonImmutable $start): array => [
                'weekStart' => $start->toDateString(),
                'weekLabel' => BurnWeek::labelFor($start),
            ], $unrecorded),
            'summary' => [
                'recordedBurn' => $forecast['recordedBurn']->toArray(),
                'recordedDays' => $forecast['recordedDays'],
                'costBudget' => $forecast['costBudget']?->toArray(),
                'budgetPercent' => $forecast['budgetPercent'],
                'forecastCost' => $forecast['forecastCost']?->toArray(),
                'margin' => $forecast['margin']?->toArray(),
                'marginPercent' => $forecast['marginPercent'],
                'weekCount' => $forecast['weekCount'],
                'hasBaseline' => $forecast['hasBaseline'],
            ],
            'position' => $engagement->positionSummary(
                $user?->can('viewAny', RateCardVersion::class) ?? false,
            ),
            'can' => [
                'record' => $user?->can('create', [BurnWeek::class, $engagement]) ?? false,
            ],
        ]);
    }

    /**
     * Record the week. Recording a week that is already on record files a
     * correction against it rather than editing anything.
     */
    public function store(RecordBurnWeekRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $burnWeek = $engagement->recordBurnWeek(
            $validated['week_start'],
            $validated['lines'],
            $user,
            $validated['note'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Week of :week recorded — :cost burned, cost-to-date now :total.', [
            'week' => $burnWeek->label(),
            'cost' => $burnWeek->cost->format(),
            'total' => $engagement->recordedBurn()->format(),
        ])]);

        return to_route('engagements.burn.index', [
            'engagement' => $engagement,
            'week' => $burnWeek->week_start->toDateString(),
        ]);
    }

    /**
     * The week the form opens on: the one the URL asks for, otherwise the
     * oldest week still missing — the entry queue is the point — and the
     * current week when the ledger is up to date.
     *
     * @param  list<CarbonImmutable>  $unrecorded
     */
    private function selectedWeek(Request $request, array $unrecorded): CarbonImmutable
    {
        $requested = $request->string('week')->value();

        if ($requested !== '' && strtotime($requested) !== false) {
            return BurnWeek::startOfWeekFor($requested);
        }

        return $unrecorded[0] ?? BurnWeek::startOfWeekFor(now());
    }

    /**
     * The ledger: every week that has been recorded, newest first, each
     * carrying the recordings it superseded. A corrected week reads as one
     * entry with its history behind it, never as two competing truths.
     *
     * @return list<array<string, mixed>>
     */
    private function ledger(Engagement $engagement): array
    {
        $weeks = $engagement->burnWeeks()->with(['entries', 'recordedBy'])->get();

        return array_values($weeks
            ->filter(fn (BurnWeek $week): bool => $week->isCurrent())
            ->map(fn (BurnWeek $week): array => [
                ...$this->summarize($week),
                'corrects' => array_values($weeks
                    ->filter(fn (BurnWeek $other): bool => ! $other->isCurrent()
                        && $other->week_start->equalTo($week->week_start))
                    ->map(fn (BurnWeek $other): array => $this->summarize($other))
                    ->all()),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(BurnWeek $week): array
    {
        return [
            'id' => $week->id,
            'weekStart' => $week->week_start->toDateString(),
            'weekLabel' => $week->label(),
            'cost' => $week->cost->toArray(),
            'days' => round($week->days(), 2),
            'note' => $week->note,
            'recordedAt' => $week->recorded_at->toFormattedDayDateString(),
            'recordedByName' => $week->recordedBy?->name,
            'supersededAt' => $week->superseded_at?->toFormattedDayDateString(),
            'entries' => $this->entries($week->entries),
        ];
    }

    /**
     * @param  Collection<int, BurnEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function entries(Collection $entries): array
    {
        return array_values($entries
            ->map(fn (BurnEntry $entry): array => [
                'id' => $entry->id,
                'roleName' => $entry->role_name,
                'personName' => $entry->person_name,
                'attributedTo' => $entry->attributedTo(),
                'days' => (float) $entry->days,
                'source' => $entry->source->value,
                'sourceLabel' => $entry->source->label(),
                'costPerDay' => $entry->cost_per_day->toArray(),
                'cost' => $entry->cost->toArray(),
            ])
            ->all());
    }
}
