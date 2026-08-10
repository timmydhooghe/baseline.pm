<?php

use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\DependencyParty;
use App\Enums\EngagementStatus;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\WorkItem;
use App\ValueObjects\Money;
use Inertia\Testing\AssertableInertia;

/**
 * Today (FA-25): the exception dashboard. Only what needs attention crosses
 * it, quiet engagements collapse to one line, and the rail carries the
 * milestones and the customer's action list. The money fixture is
 * burnSetup() from tests/Pest.php.
 */
test('today surfaces the exception queues for a manager', function () {
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement] = burnSetup();

    WorkItem::factory()->for($organization)->for($engagement)->create(['title' => 'Surprise SSO screen']);
    ChangeRequest::factory()->for($organization)->for($engagement)->create([
        'status' => ChangeRequestStatus::AwaitingApproval,
        'title' => 'Add the SSO screen',
        'respond_by' => now()->subDay(),
    ]);
    $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->subDays(3),
        'visibility' => 'internal',
    ], $manager);
    $engagement->registerRisk([
        'title' => 'Legacy exports keep failing validation',
        'probability' => RiskRating::High->value,
        'impact' => RiskRating::High->value,
        'status' => RiskStatus::Open->value,
        'visibility' => 'internal',
    ], $manager);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('engagementCount', 1)
            ->has('sections.scopeCreep', 1)
            ->where('sections.scopeCreep.0.count', 1)
            ->has('sections.changeRequests', 1)
            ->where('sections.changeRequests.0.title', 'Add the SSO screen')
            ->where('sections.changeRequests.0.overdue', true)
            ->has('sections.lateDependencies', 1)
            ->where('sections.lateDependencies.0.delayDays', 3)
            ->has('sections.escalatedRisks', 1)
            ->where('sections.escalatedRisks.0.rating', 'High × High')
            /* Four finished weeks since the baseline started, none recorded, none reported. */
            ->has('sections.unrecordedBurn', 1)
            ->where('sections.unrecordedBurn.0.count', 4)
            ->has('sections.reportDrafts', 1)
            ->where('sections.reportDrafts.0.count', 4)
            ->has('quiet', 0));
});

test('an engagement with nothing to flag collapses to one quiet line', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    foreach ($engagement->unrecordedBurnWeeks() as $week) {
        $engagement->recordBurnWeek($week, [
            ['rate_card_role_id' => $developer->id, 'days' => 2],
        ], $manager);
    }

    foreach ($engagement->dueReportWeeks() as $week) {
        $engagement->publishWeeklyReport($week, $manager);
    }

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->has('sections.unrecordedBurn', 0)
            ->has('sections.reportDrafts', 0)
            ->has('quiet', 1)
            ->where('quiet.0.name', 'ERP rollout')
            ->where('quiet.0.line', 'Baseline v1 · 0 of 2 deliverables accepted'));
});

test('the dashboard aggregates loud and quiet engagements across the portfolio', function () {
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement] = burnSetup();

    WorkItem::factory()->for($organization)->for($engagement)->create();

    /* A second engagement with nothing to flag stays to its one line. */
    $globex = Customer::factory()->for($organization)->create(['name' => 'Globex']);
    Engagement::factory()
        ->for($organization)
        ->for($globex)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'Portal build']);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('engagementCount', 2)
            ->has('sections.scopeCreep', 1)
            ->where('sections.scopeCreep.0.engagementName', 'ERP rollout')
            ->has('quiet', 1)
            ->where('quiet.0.name', 'Portal build')
            ->where('quiet.0.line', 'No approved baseline yet.'));
});

test('scope creep pricing and the governance queues stay with the roles that may read them', function () {
    ['organization' => $organization, 'engagement' => $engagement] = burnSetup();

    WorkItem::factory()->for($organization)->for($engagement)->create();
    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->has('sections.scopeCreep', 1)
            /* The queue is everyone's; its sell-rate-derived price is not. */
            ->where('sections.scopeCreep.0.price', null)
            ->has('sections.unrecordedBurn', 0)
            ->has('sections.reportDrafts', 0)
            ->where('can.viewCommercials', false));
});

test('the rail carries the milestones and what the customer owes', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create(['name' => 'Dana Mertens']);
    $organization = $manager->organization;

    $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
    ]);

    $customer = Customer::factory()->for($organization)->create(['name' => 'Acme Industries']);
    $engagement = Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'contract_value' => Money::fromCents(2000000),
        'start_date' => today()->startOfWeek(),
    ]);
    BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live',
        'baseline_date' => today()->addDays(30),
    ]);
    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    $contact = Stakeholder::factory()
        ->for($organization)
        ->for($customer)
        ->role(StakeholderRole::ProjectManager)
        ->create(['name' => 'Anders Vik']);

    $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->addDays(7),
        'visibility' => 'shared',
    ], $manager);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->has('milestones', 1)
            ->where('milestones.0.title', 'Go-live')
            ->where('milestones.0.overdue', false)
            ->has('customerActions', 1)
            ->where('customerActions.0.kind', 'dependency')
            ->where('customerActions.0.responsible', 'Anders Vik')
            ->where('customerActions.0.overdue', false));
});
