<?php

namespace App\Actions\Money;

use App\Actions\Governance\GovernanceRecordLabel;
use App\Enums\RiskStatus;
use App\Enums\WorkItemTriageStatus;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BurnEntry;
use App\Models\BurnWeek;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\WorkItem;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The engagement's derived commercial position (FA-15).
 *
 *     margin = approved revenue − forecast-at-completion cost
 *     FAC    = recorded burn + remaining effort × the pinned rate card
 *
 * The cost budget is fixed at baseline approval; the forecast moves as weeks
 * are recorded. Remaining effort is what the plan still has left for each
 * profile — planned days minus recorded days, never below zero — so a
 * forecast never books savings the engagement has not earned, and every
 * overrun shows up the moment it is recorded.
 *
 * The "why it moved" attribution decomposes the variance against plan into
 * causes carrying the records they were derived from, plus a reconciling line
 * that keeps the decomposition summing to the total in both directions: a
 * positive remainder is overburn nothing on the ledger explains, a negative
 * one is pressure the plan has absorbed so far. Nothing here is typed and
 * nothing is stored — every figure re-derives from the baseline, the recorded
 * weeks and the registers.
 *
 * All of it is cost-derived, so all of it is internal only (FA-27).
 */
class MarginForecast
{
    /**
     * How many linked records a single attribution row carries back; the rest
     * are counted. A cause with sixty work items behind it is a number with a
     * story, not sixty chips.
     */
    private const int MAX_RECORDS = 8;

    /**
     * @param  bool  $withAttribution  The rail wants the position, not the essay: it renders
     *                                 the derived figures and leaves the "why it moved"
     *                                 decomposition — and the register reads behind it — to
     *                                 the margin page.
     * @return array<string, mixed>
     */
    public function __invoke(Engagement $engagement, bool $withAttribution = true): array
    {
        $baseline = $engagement->approvedBaseline();

        if ($baseline === null) {
            return $this->withoutBaseline($engagement);
        }

        $baseline->load(['allocations.role', 'items', 'rateCardVersion.roles']);

        $weeks = $engagement->currentBurnWeeks()->with('entries')->get();
        $roles = $this->roleRows($baseline, $weeks);

        $costBudget = $baseline->costBudget();
        $recorded = $weeks->reduce(
            fn (Money $sum, BurnWeek $week): Money => $sum->add($week->cost),
            Money::zero(),
        );
        $remaining = array_reduce(
            $roles,
            fn (Money $sum, array $role): Money => $sum->add($role['remainingCost']),
            Money::zero(),
        );

        $revenue = $baseline->contract_value;
        $forecast = $recorded->add($remaining);
        $margin = $revenue->subtract($forecast);
        $variance = $forecast->subtract($costBudget);
        $exposure = $engagement->riskExposure();

        return [
            'hasBaseline' => true,
            'baselineVersion' => $baseline->version,
            'rateCardVersion' => $baseline->rateCardVersion?->version,
            'approvedRevenue' => $revenue,
            'costBudget' => $costBudget,
            'plannedMargin' => $baseline->plannedMargin(),
            'plannedMarginPercent' => $this->percentOf($baseline->plannedMargin(), $revenue),
            'recordedBurn' => $recorded,
            'recordedDays' => round(array_sum(array_column($roles, 'recordedDays')), 2),
            'remainingCost' => $remaining,
            'remainingDays' => round(array_sum(array_column($roles, 'remainingDays')), 2),
            'forecastCost' => $forecast,
            'margin' => $margin,
            'marginPercent' => $this->percentOf($margin, $revenue),
            'budgetPercent' => $this->percentOf($recorded, $costBudget),
            'forecastPercent' => $this->percentOf($forecast, $costBudget),
            'variance' => $variance,
            'weekCount' => $weeks->count(),
            'lastRecordedWeek' => $weeks->max('week_start')?->toDateString(),
            'unrecordedWeeks' => count($engagement->unrecordedBurnWeeks()),
            'roles' => $roles,
            'attribution' => $withAttribution
                ? $this->attribution($engagement, $baseline, $roles, $variance)
                : [],
            'riskBand' => [
                'weightedExposure' => $exposure['weighted'],
                'exposure' => $exposure['exposure'],
                'liveRisks' => $exposure['count'],
                'low' => $margin->subtract($exposure['weighted']),
                'lowPercent' => $this->percentOf($margin->subtract($exposure['weighted']), $revenue),
            ],
        ];
    }

    /**
     * Before approval there is no commitment to forecast against: no
     * contracted revenue, no fixed cost budget, no pinned rates. Recorded
     * burn is still real and still shown — a mobilisation week that was
     * worked is not nothing — but it is not yet a margin.
     *
     * @return array<string, mixed>
     */
    private function withoutBaseline(Engagement $engagement): array
    {
        $exposure = $engagement->riskExposure();
        $weeks = $engagement->currentBurnWeeks()->with('entries')->get();

        return [
            'hasBaseline' => false,
            'baselineVersion' => null,
            'rateCardVersion' => null,
            'approvedRevenue' => null,
            'costBudget' => null,
            'plannedMargin' => null,
            'plannedMarginPercent' => null,
            'recordedBurn' => $weeks->reduce(
                fn (Money $sum, BurnWeek $week): Money => $sum->add($week->cost),
                Money::zero(),
            ),
            'recordedDays' => round($weeks->sum(fn (BurnWeek $week): float => $week->days()), 2),
            'remainingCost' => null,
            'remainingDays' => null,
            'forecastCost' => null,
            'margin' => null,
            'marginPercent' => null,
            'budgetPercent' => null,
            'forecastPercent' => null,
            'variance' => null,
            'weekCount' => $weeks->count(),
            'lastRecordedWeek' => $weeks->max('week_start')?->toDateString(),
            'unrecordedWeeks' => 0,
            'roles' => [],
            'attribution' => [],
            'riskBand' => [
                'weightedExposure' => $exposure['weighted'],
                'exposure' => $exposure['exposure'],
                'liveRisks' => $exposure['count'],
                'low' => null,
                'lowPercent' => null,
            ],
        ];
    }

    /**
     * The forecast per profile: what the plan allocated, what the recorded
     * weeks have consumed, and what the plan has left. Profiles are matched
     * by name rather than by role id, because an approved change request can
     * pin a later rate card version and the same profile has to keep
     * accumulating against the plan it was budgeted in.
     *
     * A profile that burned days it was never allocated shows a planned zero
     * and a remaining zero: there is nothing left of a budget that never
     * existed, and its whole cost is variance.
     *
     * @param  Collection<int, BurnWeek>  $weeks
     * @return list<array<string, mixed>>
     */
    private function roleRows(Baseline $baseline, Collection $weeks): array
    {
        $planned = [];
        $rates = [];

        foreach ($baseline->allocations as $allocation) {
            /** @var BaselineAllocation $allocation */
            $name = $allocation->role->name;
            $planned[$name] = ($planned[$name] ?? 0.0) + (float) $allocation->days;
            $rates[$name] ??= $allocation->role->cost_per_day;
        }

        $recordedDays = [];
        $recordedCost = [];

        foreach ($weeks as $week) {
            foreach ($week->entries as $entry) {
                /** @var BurnEntry $entry */
                $name = $entry->role_name;
                $recordedDays[$name] = ($recordedDays[$name] ?? 0.0) + (float) $entry->days;
                $recordedCost[$name] = ($recordedCost[$name] ?? Money::zero())->add($entry->cost);
                $rates[$name] ??= $entry->cost_per_day;
            }
        }

        $names = array_values(array_unique([...array_keys($planned), ...array_keys($recordedDays)]));
        sort($names);

        return array_map(function (string $name) use ($planned, $recordedDays, $recordedCost, $rates): array {
            $plannedDays = round($planned[$name] ?? 0.0, 2);
            $spentDays = round($recordedDays[$name] ?? 0.0, 2);
            $remainingDays = round(max(0.0, $plannedDays - $spentDays), 2);
            $rate = $rates[$name] ?? Money::zero();

            return [
                'name' => $name,
                'costPerDay' => $rate,
                'plannedDays' => $plannedDays,
                'plannedCost' => Money::fromCents((int) round($plannedDays * $rate->amount)),
                'recordedDays' => $spentDays,
                'recordedCost' => $recordedCost[$name] ?? Money::zero(),
                'remainingDays' => $remainingDays,
                'remainingCost' => Money::fromCents((int) round($remainingDays * $rate->amount)),
                'overrunDays' => round(max(0.0, $spentDays - $plannedDays), 2),
                'unplanned' => $plannedDays === 0.0 && $spentDays > 0.0,
            ];
        }, $names);
    }

    /**
     * Why the forecast moved away from the cost budget (FA-15). Each cause is
     * derived from records that exist — absorbed scope creep from the triage
     * decisions, delay from the dependency register's day-for-day clock,
     * materialised risk from the register, staffing premium from burn booked
     * against profiles the plan never budgeted. The reconciling line closes
     * the sum against the variance either way, so the decomposition never
     * pretends to explain more or less than actually moved.
     *
     * @param  list<array<string, mixed>>  $roles
     * @return list<array<string, mixed>>
     */
    private function attribution(Engagement $engagement, Baseline $baseline, array $roles, Money $variance): array
    {
        $rates = $baseline->blendedDayRates();
        $causes = array_values(array_filter([
            $this->absorbedScopeCreep($engagement, $rates),
            $this->dependencyDelay($engagement, $rates),
            $this->materialisedRisk($engagement),
            $this->staffingPremium($roles),
        ], fn (?array $cause): bool => $cause !== null));

        $explained = array_reduce(
            $causes,
            fn (Money $sum, array $cause): Money => $sum->add($cause['amount']),
            Money::zero(),
        );

        $remainder = $variance->subtract($explained);

        if ($causes === [] && $remainder->isZero()) {
            return [];
        }

        $causes[] = [
            'key' => 'unattributed',
            'label' => $remainder->isNegative()
                ? __('Absorbed by the plan')
                : __('Unattributed overburn'),
            'detail' => $remainder->isNegative()
                ? __('Pressure the registers carry that the cost budget has soaked up so far — it has not reached the forecast.')
                : __('Recorded burn beyond plan that no register explains yet. Name it in a decision, a risk or a change request.'),
            'amount' => $remainder,
            'records' => [],
            'moreCount' => 0,
        ];

        return $causes;
    }

    /**
     * Scope creep classified as existing scope is absorbed by margin by
     * definition (FA-9) — the work is done, the customer is not billed, and
     * the cost lands here.
     *
     * @param  array{cost: Money, sell: Money}|null  $rates
     * @return array<string, mixed>|null
     */
    private function absorbedScopeCreep(Engagement $engagement, ?array $rates): ?array
    {
        $items = $engagement->workItems()
            ->where('triage_status', WorkItemTriageStatus::ExistingScope)
            ->with('worklogs')
            ->get();

        $cost = Money::zero();
        $priced = [];

        foreach ($items as $item) {
            /** @var WorkItem $item */
            $itemCost = $item->priceEffort($rates)['cost'];

            /*
             * An item whose effort has no day equivalence — a points
             * estimate with nothing logged — carries no traceable cost.
             * It stays in the triage inbox as visible risk; inventing a
             * number for it here would be exactly the free-typed figure the
             * ledger exists to refuse.
             */
            if ($itemCost === null) {
                continue;
            }

            $cost = $cost->add($itemCost);
            $priced[] = $item;
        }

        if ($priced === []) {
            return null;
        }

        return [
            'key' => 'absorbed_scope_creep',
            'label' => __('Absorbed scope creep'),
            'detail' => trans_choice(
                '{1}One work item classified as existing scope, priced at the baseline\'s blended cost rate.|[2,*]:count work items classified as existing scope, priced at the baseline\'s blended cost rate.',
                count($priced),
                ['count' => count($priced)],
            ),
            'amount' => $cost,
            ...$this->records(new Collection($priced)),
        ];
    }

    /**
     * Day-for-day delay from the dependency register (FA-20) costs the team
     * that carries it. Each accrued delay day is priced at one profile's
     * blended cost day — the conservative reading of holding a team through
     * a slip, and one that traces to the register rather than to a feeling.
     *
     * @param  array{cost: Money, sell: Money}|null  $rates
     * @return array<string, mixed>|null
     */
    private function dependencyDelay(Engagement $engagement, ?array $rates): ?array
    {
        if ($rates === null) {
            return null;
        }

        $delayed = $engagement->dependencies()
            ->get()
            ->filter(fn (Dependency $dependency): bool => $dependency->delayDays() > 0)
            ->values();

        if ($delayed->isEmpty()) {
            return null;
        }

        $days = $delayed->sum(fn (Dependency $dependency): int => $dependency->delayDays());

        return [
            'key' => 'dependency_delay',
            'label' => __('Dependency delay'),
            'detail' => trans_choice(
                '{1}:days delay day across one dependency, at the blended cost rate of :rate a day.|[2,*]:days delay days across :count dependencies, at the blended cost rate of :rate a day.',
                $delayed->count(),
                ['days' => $days, 'count' => $delayed->count(), 'rate' => $rates['cost']->format()],
            ),
            'amount' => Money::fromCents((int) round($days * $rates['cost']->amount)),
            ...$this->records($delayed),
        ];
    }

    /**
     * A risk that materialised stopped being an exposure and became a cost
     * (FA-17, FA-19). Its full priced exposure lands on the forecast — the
     * probability weighting was for the risk band, and the band is for risks
     * that have not happened.
     *
     * @return array<string, mixed>|null
     */
    private function materialisedRisk(Engagement $engagement): ?array
    {
        $risks = $engagement->risks()
            ->where('status', RiskStatus::Materialised)
            ->with('exposures.role')
            ->get()
            ->filter(fn (Risk $risk): bool => ! $risk->exposure()->isZero())
            ->values();

        if ($risks->isEmpty()) {
            return null;
        }

        return [
            'key' => 'risk_materialised',
            'label' => __('Materialised risk'),
            'detail' => trans_choice(
                '{1}One risk on the register materialised, at the exposure it was priced with.|[2,*]:count risks on the register materialised, at the exposure they were priced with.',
                $risks->count(),
                ['count' => $risks->count()],
            ),
            'amount' => $risks->reduce(
                fn (Money $sum, Risk $risk): Money => $sum->add($risk->exposure()),
                Money::zero(),
            ),
            ...$this->records($risks),
        ];
    }

    /**
     * Burn booked against a profile the plan never allocated: somebody had to
     * be staffed who was not budgeted. Its whole recorded cost is variance,
     * because there is no plan for it to consume.
     *
     * @param  list<array<string, mixed>>  $roles
     * @return array<string, mixed>|null
     */
    private function staffingPremium(array $roles): ?array
    {
        $unplanned = array_values(array_filter($roles, fn (array $role): bool => $role['unplanned'] === true));

        if ($unplanned === []) {
            return null;
        }

        return [
            'key' => 'staffing_premium',
            'label' => __('Staffing premium'),
            'detail' => __('Recorded against profiles the baseline never allocated: :roles.', [
                'roles' => implode(', ', array_column($unplanned, 'name')),
            ]),
            'amount' => array_reduce(
                $unplanned,
                fn (Money $sum, array $role): Money => $sum->add($role['recordedCost']),
                Money::zero(),
            ),
            'records' => array_map(fn (array $role): array => [
                'type' => 'profile',
                'type_label' => __('Profile'),
                'id' => $role['name'],
                'title' => __(':days d recorded, none planned', ['days' => $role['recordedDays']]),
            ], $unplanned),
            'moreCount' => 0,
        ];
    }

    /**
     * The records behind a cause, as the chips the ledgers render, trimmed to
     * what a reader can take in.
     *
     * @param  Collection<int, covariant Model>  $records
     * @return array{records: list<array{type: string, type_label: string, id: string, title: string}>, moreCount: int}
     */
    private function records(Collection $records): array
    {
        return [
            'records' => array_values($records
                ->take(self::MAX_RECORDS)
                ->map(fn (Model $record): array => GovernanceRecordLabel::chip($record))
                ->all()),
            'moreCount' => max(0, $records->count() - self::MAX_RECORDS),
        ];
    }

    /**
     * A ratio as a percentage to one decimal, or null when the denominator
     * carries nothing to be a percentage of.
     */
    private function percentOf(Money $part, Money $whole): ?float
    {
        if ($whole->isZero()) {
            return null;
        }

        return round($part->amount / $whole->amount * 100, 1);
    }
}
