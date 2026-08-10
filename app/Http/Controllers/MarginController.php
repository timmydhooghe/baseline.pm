<?php

namespace App\Http\Controllers;

use App\Models\BurnWeek;
use App\Models\Engagement;
use App\Models\RateCardVersion;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The margin derivation behind the rail (FA-15, FA-17):
 *
 *     margin = approved revenue − forecast-at-completion
 *     FAC    = recorded burn + remaining effort × the pinned rate card
 *
 * Every figure on the page is derived and every one of them shows its
 * working: the role mix it came from, the weeks that were recorded against
 * it, and a "why it moved" decomposition of the variance against plan into
 * causes carrying the records they were read from. The risk register's
 * probability-weighted exposure closes it out as a margin risk band.
 *
 * All of it is cost-derived, so the page sits behind the roles that may read
 * the rate card (FA-27).
 */
class MarginController extends Controller
{
    public function show(Request $request, Engagement $engagement): Response
    {
        Gate::authorize('view', $engagement);
        Gate::authorize('viewAny', RateCardVersion::class);

        $derived = $engagement->marginForecast();
        $user = $request->user();

        return Inertia::render('engagements/margin', [
            'engagement' => [
                'id' => $engagement->id,
                'name' => $engagement->name,
                'status' => $engagement->status->value,
                'statusLabel' => $engagement->status->label(),
            ],
            'forecast' => [
                'hasBaseline' => $derived['hasBaseline'],
                'baselineVersion' => $derived['baselineVersion'],
                'rateCardVersion' => $derived['rateCardVersion'],
                'approvedRevenue' => $this->money($derived['approvedRevenue']),
                'costBudget' => $this->money($derived['costBudget']),
                'plannedMargin' => $this->money($derived['plannedMargin']),
                'plannedMarginPercent' => $derived['plannedMarginPercent'],
                'recordedBurn' => $this->money($derived['recordedBurn']),
                'recordedDays' => $derived['recordedDays'],
                'remainingCost' => $this->money($derived['remainingCost']),
                'remainingDays' => $derived['remainingDays'],
                'forecastCost' => $this->money($derived['forecastCost']),
                'margin' => $this->money($derived['margin']),
                'marginPercent' => $derived['marginPercent'],
                'budgetPercent' => $derived['budgetPercent'],
                'forecastPercent' => $derived['forecastPercent'],
                'variance' => $this->money($derived['variance']),
                'weekCount' => $derived['weekCount'],
                'lastRecordedWeek' => $derived['lastRecordedWeek'] === null
                    ? null
                    : BurnWeek::labelFor($derived['lastRecordedWeek']),
                'unrecordedWeeks' => $derived['unrecordedWeeks'],
            ],
            'roles' => array_map(fn (array $role): array => [
                'name' => $role['name'],
                'costPerDay' => $role['costPerDay']->toArray(),
                'plannedDays' => $role['plannedDays'],
                'plannedCost' => $role['plannedCost']->toArray(),
                'recordedDays' => $role['recordedDays'],
                'recordedCost' => $role['recordedCost']->toArray(),
                'remainingDays' => $role['remainingDays'],
                'remainingCost' => $role['remainingCost']->toArray(),
                'overrunDays' => $role['overrunDays'],
                'unplanned' => $role['unplanned'],
            ], $derived['roles']),
            'attribution' => array_map(fn (array $cause): array => [
                'key' => $cause['key'],
                'label' => $cause['label'],
                'detail' => $cause['detail'],
                'amount' => $cause['amount']->toArray(),
                'records' => $cause['records'],
                'moreCount' => $cause['moreCount'],
            ], $derived['attribution']),
            'riskBand' => [
                'liveRisks' => $derived['riskBand']['liveRisks'],
                'exposure' => $derived['riskBand']['exposure']->toArray(),
                'weightedExposure' => $derived['riskBand']['weightedExposure']->toArray(),
                'low' => $this->money($derived['riskBand']['low']),
                'lowPercent' => $derived['riskBand']['lowPercent'],
            ],
            'position' => $engagement->positionSummary(true),
            'can' => [
                'recordBurn' => $user?->can('create', [BurnWeek::class, $engagement]) ?? false,
            ],
        ]);
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}|null
     */
    private function money(?Money $money): ?array
    {
        return $money?->toArray();
    }
}
