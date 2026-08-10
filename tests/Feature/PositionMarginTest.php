<?php

use App\Actions\Money\MarginForecast;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\DependencyParty;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Enums\WorkItemTriageStatus;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\WorkItem;
use App\ValueObjects\Money;
use Inertia\Testing\AssertableInertia;

/**
 * The position rail and the margin derivation behind it (FA-14, FA-15,
 * FA-17), read against the shared money-loop fixture in tests/Pest.php: a
 * €50,000 contract, a €12,000 cost budget (20 developer days at €450, 5 lead
 * days at €600) and therefore a €38,000 planned margin.
 */
beforeEach(function (): void {
    $this->fixture = burnSetup();
});

/**
 * @return array<string, mixed>
 */
function forecast(): array
{
    return app(MarginForecast::class)(test()->fixture['engagement']->fresh());
}

it('forecasts at the cost budget while the plan still covers the remaining effort', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    $derived = forecast();

    /* Five days burned at €450, fifteen developer and five lead days left. */
    expect($derived['recordedBurn']->amount)->toBe(225000)
        ->and($derived['remainingCost']->amount)->toBe(675000 + 300000)
        ->and($derived['forecastCost']->amount)->toBe(1200000)
        ->and($derived['costBudget']->amount)->toBe(1200000)
        ->and($derived['variance']->amount)->toBe(0)
        ->and($derived['margin']->amount)->toBe(3800000)
        ->and($derived['plannedMargin']->amount)->toBe(3800000)
        ->and($derived['budgetPercent'])->toBe(18.8)
        ->and($derived['forecastPercent'])->toBe(100.0)
        ->and($derived['attribution'])->toBe([]);
});

it('moves margin the moment a profile burns past its plan', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    /* Twenty-five developer days against a plan of twenty. */
    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 25],
    ], $manager);

    $derived = forecast();

    expect($derived['recordedBurn']->amount)->toBe(1125000)
        /* Nothing left of the developer budget; the lead's five days remain. */
        ->and($derived['remainingCost']->amount)->toBe(300000)
        ->and($derived['forecastCost']->amount)->toBe(1425000)
        ->and($derived['variance']->amount)->toBe(225000)
        ->and($derived['margin']->format())->toBe('€ 35.750,00');

    $row = collect($derived['roles'])->firstWhere('name', 'Developer');

    expect($row['plannedDays'])->toBe(20.0)
        ->and($row['recordedDays'])->toBe(25.0)
        ->and($row['remainingDays'])->toBe(0.0)
        ->and($row['overrunDays'])->toBe(5.0)
        ->and($row['unplanned'])->toBeFalse();
});

it('decomposes the variance into causes that sum to it exactly', function (): void {
    [
        'manager' => $manager,
        'engagement' => $engagement,
        'organization' => $organization,
        'developer' => $developer,
        'designer' => $designer,
        'checkout' => $checkout,
    ] = $this->fixture;

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 25],
        /* A profile the baseline never planned: all of it is variance. */
        ['rate_card_role_id' => $designer->id, 'days' => 4],
    ], $manager);

    /* Scope creep absorbed into existing scope: two logged days of work. */
    $absorbed = WorkItem::factory()->for($organization)->for($engagement)->create(['title' => 'Extra VAT rules']);
    $absorbed->addManualWorklog(16, lastWeek()->addDay()->toDateString(), $manager);
    $absorbed->triage(WorkItemTriageStatus::ExistingScope, $manager, $checkout);

    /* A risk that stopped being a risk: its full priced exposure lands. */
    $risk = $engagement->registerRisk(['title' => 'Migration source unusable'], $manager);
    $risk->syncExposures([['rate_card_role_id' => $developer->id, 'days' => 3]], $manager);
    $risk->reassess(['status' => RiskStatus::Materialised], $manager);

    $derived = forecast();
    $causes = collect($derived['attribution'])->keyBy('key');

    expect($causes->keys()->all())
        ->toContain('absorbed_scope_creep', 'staffing_premium', 'risk_materialised', 'unattributed')
        /* Four designer days at €500. */
        ->and($causes['staffing_premium']['amount']->amount)->toBe(200000)
        /* Three developer days at €450, unweighted — it happened. */
        ->and($causes['risk_materialised']['amount']->amount)->toBe(135000)
        /* Two days at the €480 blended cost rate of the planned role mix. */
        ->and($causes['absorbed_scope_creep']['amount']->amount)->toBe(96000)
        ->and($causes['absorbed_scope_creep']['records'])->toHaveCount(1)
        ->and($causes['absorbed_scope_creep']['records'][0]['title'])->toBe('Extra VAT rules');

    $summed = collect($derived['attribution'])->reduce(
        fn (Money $total, array $cause): Money => $total->add($cause['amount']),
        Money::zero(),
    );

    expect($summed->amount)->toBe($derived['variance']->amount);
});

it('reconciles downwards when the registers carry more than the plan has lost', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    /* Under plan, so the forecast still matches the budget exactly... */
    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    /* ...while a materialised risk says the pressure is real. */
    $risk = $engagement->registerRisk(['title' => 'Third-party API rewrite'], $manager);
    $risk->syncExposures([['rate_card_role_id' => $developer->id, 'days' => 4]], $manager);
    $risk->reassess(['status' => RiskStatus::Materialised], $manager);

    $causes = collect(forecast()['attribution'])->keyBy('key');

    expect(forecast()['variance']->amount)->toBe(0)
        ->and($causes['risk_materialised']['amount']->amount)->toBe(180000)
        /* The reconciling line runs negative: the plan has absorbed it so far. */
        ->and($causes['unattributed']['amount']->amount)->toBe(-180000)
        ->and($causes['unattributed']['label'])->toBe('Absorbed by the plan');
});

it('prices dependency delay into the variance from the register clock', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'customer' => $customer] = $this->fixture;

    $contact = Stakeholder::factory()
        ->for($engagement->organization)
        ->for($customer)
        ->role(StakeholderRole::ProjectManager)
        ->create(['name' => 'Anders Vik']);

    $engagement->registerDependency([
        'title' => 'Production API credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->subDays(3),
        'visibility' => 'shared',
    ], $manager);

    $causes = collect(forecast()['attribution'])->keyBy('key');

    /* Three delay days at the €480 blended cost rate. */
    expect($causes['dependency_delay']['amount']->amount)->toBe(144000)
        ->and($causes['dependency_delay']['records'][0]['title'])->toBe('Production API credentials');
});

it('rolls probability-weighted risk exposure into a margin risk band', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    $risk = $engagement->registerRisk([
        'title' => 'Data cleanup runs long',
        'probability' => RiskRating::Medium,
        'impact' => RiskRating::High,
    ], $manager);
    $risk->syncExposures([['rate_card_role_id' => $developer->id, 'days' => 10]], $manager);

    $band = forecast()['riskBand'];

    /* Ten days at €450 is €4,500 of exposure; medium probability weights half. */
    expect($band['exposure']->amount)->toBe(450000)
        ->and($band['weightedExposure']->amount)->toBe(225000)
        ->and($band['low']->amount)->toBe(3800000 - 225000)
        ->and($band['liveRisks'])->toBe(1);
});

it('carries the whole waterfall on the position rail', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    ChangeRequest::factory()->for($engagement->organization)->for($engagement)->create([
        'title' => 'Second currency',
        'status' => ChangeRequestStatus::AwaitingApproval,
        'customer_price' => Money::fromCents(400000),
    ]);
    ChangeRequest::factory()->for($engagement->organization)->for($engagement)->create([
        'title' => 'Nothing costed yet',
        'status' => ChangeRequestStatus::Draft,
        'customer_price' => null,
    ]);

    /* Unresolved scope creep, two logged days at the blended sell rate. */
    WorkItem::factory()->for($engagement->organization)->for($engagement)->create()
        ->addManualWorklog(16, lastWeek()->addDay()->toDateString(), $manager);

    $this->actingAs($manager)
        ->get(route('engagements.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('position.contracted.amount', 5000000)
            ->where('position.baselineVersion', 1)
            ->where('position.pendingChange.count', 2)
            ->where('position.pendingChange.unpriced', 1)
            ->where('position.pendingChange.price.amount', 400000)
            ->where('position.unbilledRisk.count', 1)
            ->where('position.unbilledRisk.price.amount', 162800)
            ->where('position.burn.recorded.amount', 225000)
            ->where('position.burn.budgetPercent', 18.8)
            ->where('position.burn.weeks', 1)
            ->where('position.burn.unrecordedWeeks', 3)
            ->where('position.margin.forecast.amount', 3800000)
            ->where('position.margin.variance.amount', 0)
            ->etc()
        );
});

it('strips burn and margin from the rail for viewers without rate card access', function (): void {
    ['engagement' => $engagement, 'organization' => $organization] = $this->fixture;

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('engagements.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('position.burn', null)
            ->where('position.margin', null)
            ->where('position.unbilledRisk.price', null)
            /* Contract figures they already read elsewhere are not stripped. */
            ->where('position.contracted.amount', 5000000)
            ->where('position.pendingChange.count', 0)
            ->etc()
        );
});

it('shows the derivation on the margin page with every figure traced', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 25],
    ], $manager);

    $this->actingAs($manager)
        ->get(route('engagements.margin.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/margin')
            ->where('forecast.hasBaseline', true)
            ->where('forecast.approvedRevenue.formatted', '€ 50.000,00')
            ->where('forecast.costBudget.formatted', '€ 12.000,00')
            ->where('forecast.recordedBurn.formatted', '€ 11.250,00')
            ->where('forecast.remainingCost.formatted', '€ 3.000,00')
            ->where('forecast.forecastCost.formatted', '€ 14.250,00')
            ->where('forecast.margin.formatted', '€ 35.750,00')
            ->where('forecast.variance.formatted', '€ 2.250,00')
            ->where('forecast.rateCardVersion', 1)
            /* Only profiles that were planned or recorded — the designer is neither. */
            ->has('roles', 2)
            ->has('attribution', 1)
            ->where('attribution.0.key', 'unattributed')
            ->where('attribution.0.amount.amount', 225000)
            ->etc()
        );
});

it('reports burn without a margin while no baseline is approved', function (): void {
    ['manager' => $manager, 'organization' => $organization, 'version' => $version] = $this->fixture;

    $draft = Engagement::factory()
        ->for($organization)
        ->for(Customer::factory()->for($organization)->create())
        ->create(['name' => 'Discovery sprint']);

    $draft->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $version->roles->firstWhere('name', 'Developer')->id, 'days' => 3],
    ], $manager);

    $derived = app(MarginForecast::class)($draft->fresh());

    expect($derived['hasBaseline'])->toBeFalse()
        ->and($derived['recordedBurn']->amount)->toBe(135000)
        ->and($derived['recordedDays'])->toBe(3.0)
        ->and($derived['margin'])->toBeNull()
        ->and($derived['costBudget'])->toBeNull()
        ->and($derived['attribution'])->toBe([]);

    $this->actingAs($manager)
        ->get(route('engagements.margin.show', $draft))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('forecast.hasBaseline', false)
            ->where('forecast.recordedBurn.formatted', '€ 1.350,00')
            ->where('forecast.margin', null)
            ->etc()
        );
});

it('keeps the forecast reading the correction, never the week it replaced', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = $this->fixture;

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 25],
    ], $manager);

    expect(forecast()['variance']->amount)->toBe(225000);

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 12],
    ], $manager, 'Double-counted the design sprint');

    $derived = forecast();

    expect($derived['recordedBurn']->amount)->toBe(540000)
        ->and($derived['forecastCost']->amount)->toBe(1200000)
        ->and($derived['variance']->amount)->toBe(0)
        ->and($derived['margin']->amount)->toBe(3800000);
});

it('accrues signed-off value to the accepted block', function (): void {
    ['engagement' => $engagement, 'checkout' => $checkout] = $this->fixture;

    Deliverable::query()->where('baseline_item_id', $checkout->id)->sole()->forceFill([
        'status' => DeliverableStatus::Accepted,
        'accepted_at' => now(),
        'accepted_value' => Money::fromCents(3000000),
    ])->save();

    $position = Engagement::query()->findOrFail($engagement->id)->positionSummary(true);

    expect($position['accepted']['count'])->toBe(1)
        ->and($position['accepted']['total'])->toBe(2)
        ->and($position['accepted']['value']['amount'])->toBe(3000000)
        ->and($position['pendingChange']['count'])->toBe(0)
        ->and($position['burn']['recorded']['amount'])->toBe(0);
});
