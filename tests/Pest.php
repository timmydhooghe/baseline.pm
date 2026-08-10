<?php

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Enums\UserRole;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineItem;
use App\Models\BurnWeek;
use App\Models\Customer;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A delivery manager on an active engagement executing against approved
 * baseline v1: a €50,000 contract over two deliverables, a role mix of 20
 * developer days at €450/day and 5 lead days at €600/day — a €12,000 cost
 * budget and a €38,000 planned margin. The rate card also carries a designer
 * the baseline never planned, so unplanned staffing has something to be. The
 * baseline runs from four weeks ago, so finished weeks exist to be recorded.
 *
 * Shared by the weekly burn and margin suites: both read the same money loop
 * from opposite ends, and a fixture they could disagree about would prove
 * nothing.
 *
 * @return array<string, mixed>
 */
function burnSetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create(['name' => 'Dana Mertens']);
    $organization = $manager->organization;

    $version = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
        ['name' => 'Delivery lead', 'cost_per_day' => Money::fromCents(60000), 'sell_per_day' => Money::fromCents(95000)],
        ['name' => 'Designer', 'cost_per_day' => Money::fromCents(50000), 'sell_per_day' => Money::fromCents(90000)],
    ]);

    $customer = Customer::factory()->for($organization)->create(['name' => 'Acme Industries']);
    $engagement = Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'rate_card_version_id' => $version->id,
        'contract_value' => Money::fromCents(5000000),
        'start_date' => BurnWeek::startOfWeekFor(now())->subWeeks(4),
        'end_date' => BurnWeek::startOfWeekFor(now())->addWeeks(8),
    ]);

    $checkout = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Checkout flow',
        'value' => Money::fromCents(3000000),
        'position' => 1,
    ]);
    $reporting = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Reporting pack',
        'value' => Money::fromCents(2000000),
        'position' => 2,
    ]);

    $developer = $version->roles->firstWhere('name', 'Developer');
    $lead = $version->roles->firstWhere('name', 'Delivery lead');

    BaselineAllocation::factory()->for($organization)->for($baseline)->create([
        'baseline_item_id' => $checkout->id,
        'rate_card_role_id' => $developer->id,
        'days' => '20',
    ]);
    BaselineAllocation::factory()->for($organization)->for($baseline)->create([
        'baseline_item_id' => $reporting->id,
        'rate_card_role_id' => $lead->id,
        'days' => '5',
    ]);

    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    Deliverable::provisionForBaseline($baseline);

    return [
        'manager' => $manager,
        'organization' => $organization,
        'customer' => $customer,
        'engagement' => $engagement,
        'baseline' => $baseline,
        'version' => $version,
        'developer' => $developer,
        'lead' => $lead,
        'designer' => $version->roles->firstWhere('name', 'Designer'),
        'checkout' => $checkout,
        'reporting' => $reporting,
    ];
}

/**
 * The Monday of the week that finished most recently — the week the burn
 * entry queue opens on.
 */
function lastWeek(): CarbonImmutable
{
    return BurnWeek::startOfWeekFor(now())->subWeek();
}
