<?php

use App\Enums\BaselineStatus;
use App\Enums\UserRole;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineItem;
use App\Models\RateCardVersion;
use App\Models\User;
use App\ValueObjects\Money;

/**
 * A manager with a priced baseline: two deliverables and a pinned rate card
 * with a Developer (€450/day cost) and a Designer (€380/day cost).
 *
 * @return array{0: User, 1: Baseline, 2: BaselineItem, 3: BaselineItem, 4: RateCardVersion}
 */
function pricedBaseline(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $version = $manager->organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
        ['name' => 'Designer', 'cost_per_day' => Money::fromCents(38000), 'sell_per_day' => Money::fromCents(65000)],
    ]);

    $baseline = Baseline::factory()->for($manager->organization)->create([
        'rate_card_version_id' => $version->id,
        'contract_value' => Money::fromCents(2000000),
    ]);

    $first = BaselineItem::factory()->for($manager->organization)->for($baseline)->create(['position' => 1]);
    $second = BaselineItem::factory()->for($manager->organization)->for($baseline)->create(['position' => 2]);

    return [$manager, $baseline, $first, $second, $version];
}

test('the role mix saves and every cost derives from the pinned rate card', function () {
    [$manager, $baseline, $first, $second, $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();

    $this->actingAs($manager)
        ->put(route('baselines.commercials.update', $baseline), [
            'allocations' => [
                ['baseline_item_id' => $first->id, 'rate_card_role_id' => $developer->id, 'days' => '10'],
                ['baseline_item_id' => $second->id, 'rate_card_role_id' => $developer->id, 'days' => '5'],
                ['baseline_item_id' => null, 'rate_card_role_id' => $developer->id, 'days' => '3'],
            ],
        ])
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    $baseline->refresh()->load('allocations.role');

    expect($baseline->allocations)->toHaveCount(3)
        ->and($baseline->costBudget()->amount)->toBe(810000)
        ->and($baseline->deliveryManagementCost()->amount)->toBe(135000)
        ->and($baseline->plannedMargin()->amount)->toBe(2000000 - 810000);
});

test('delivery management spreads pro-rata across deliverable budgets', function () {
    [$manager, $baseline, $first, $second, $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();

    $this->actingAs($manager)->put(route('baselines.commercials.update', $baseline), [
        'allocations' => [
            ['baseline_item_id' => $first->id, 'rate_card_role_id' => $developer->id, 'days' => '10'],
            ['baseline_item_id' => $second->id, 'rate_card_role_id' => $developer->id, 'days' => '5'],
            ['baseline_item_id' => null, 'rate_card_role_id' => $developer->id, 'days' => '3'],
        ],
    ]);

    $budgets = $baseline->refresh()->load('allocations.role')->deliverableCostBudgets();

    // Direct: €4,500 and €2,250; management €1,350 splits 2:1 with them.
    expect($budgets[$first->id]['direct']->amount)->toBe(450000)
        ->and($budgets[$first->id]['budget']->amount)->toBe(540000)
        ->and($budgets[$second->id]['direct']->amount)->toBe(225000)
        ->and($budgets[$second->id]['budget']->amount)->toBe(270000);
});

test('delivery management splits evenly while no deliverable has direct cost', function () {
    [$manager, $baseline, $first, $second, $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();

    $this->actingAs($manager)->put(route('baselines.commercials.update', $baseline), [
        'allocations' => [
            ['baseline_item_id' => null, 'rate_card_role_id' => $developer->id, 'days' => '3'],
        ],
    ]);

    $budgets = $baseline->refresh()->load('allocations.role')->deliverableCostBudgets();

    expect($budgets[$first->id]['budget']->amount)->toBe(67500)
        ->and($budgets[$second->id]['budget']->amount)->toBe(67500);
});

test('fractional days price to whole cents', function () {
    [$manager, $baseline, $first, , $version] = pricedBaseline();
    $designer = $version->roles()->where('name', 'Designer')->sole();

    $this->actingAs($manager)->put(route('baselines.commercials.update', $baseline), [
        'allocations' => [
            ['baseline_item_id' => $first->id, 'rate_card_role_id' => $designer->id, 'days' => '2.5'],
        ],
    ]);

    expect($baseline->refresh()->load('allocations.role')->costBudget()->amount)->toBe(95000);
});

test('roles outside the pinned rate card version are refused', function () {
    [$manager, $baseline, $first] = pricedBaseline();
    $laterVersion = $manager->organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(50000), 'sell_per_day' => Money::fromCents(85000)],
    ]);
    $laterRole = $laterVersion->roles()->sole();

    $this->actingAs($manager)
        ->put(route('baselines.commercials.update', $baseline), [
            'allocations' => [
                ['baseline_item_id' => $first->id, 'rate_card_role_id' => $laterRole->id, 'days' => '5'],
            ],
        ])
        ->assertInvalid(['allocations.0.rate_card_role_id']);
});

test('a role can only appear once per line', function () {
    [$manager, $baseline, $first, , $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();

    $this->actingAs($manager)
        ->put(route('baselines.commercials.update', $baseline), [
            'allocations' => [
                ['baseline_item_id' => $first->id, 'rate_card_role_id' => $developer->id, 'days' => '5'],
                ['baseline_item_id' => $first->id, 'rate_card_role_id' => $developer->id, 'days' => '3'],
            ],
        ])
        ->assertInvalid(['allocations.1.rate_card_role_id']);
});

test('role mixes only attach to deliverables of the same baseline', function () {
    [$manager, $baseline, , , $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();
    $foreignItem = BaselineItem::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->put(route('baselines.commercials.update', $baseline), [
            'allocations' => [
                ['baseline_item_id' => $foreignItem->id, 'rate_card_role_id' => $developer->id, 'days' => '5'],
            ],
        ])
        ->assertInvalid(['allocations.0.baseline_item_id']);
});

test('role mixes do not attach to milestones', function () {
    [$manager, $baseline, , , $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();
    $milestone = BaselineItem::factory()->for($manager->organization)->for($baseline)->completeMilestone()->create();

    $this->actingAs($manager)
        ->put(route('baselines.commercials.update', $baseline), [
            'allocations' => [
                ['baseline_item_id' => $milestone->id, 'rate_card_role_id' => $developer->id, 'days' => '5'],
            ],
        ])
        ->assertInvalid(['allocations.0.baseline_item_id']);
});

test('saving again replaces the whole role mix', function () {
    [$manager, $baseline, $first, , $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();
    $designer = $version->roles()->where('name', 'Designer')->sole();

    $this->actingAs($manager)->put(route('baselines.commercials.update', $baseline), [
        'allocations' => [
            ['baseline_item_id' => $first->id, 'rate_card_role_id' => $developer->id, 'days' => '10'],
            ['baseline_item_id' => null, 'rate_card_role_id' => $developer->id, 'days' => '2'],
        ],
    ]);
    $this->actingAs($manager)->put(route('baselines.commercials.update', $baseline), [
        'allocations' => [
            ['baseline_item_id' => $first->id, 'rate_card_role_id' => $designer->id, 'days' => '4'],
        ],
    ]);

    $allocations = BaselineAllocation::query()->get();

    expect($allocations)->toHaveCount(1)
        ->and($allocations->first()?->rate_card_role_id)->toBe($designer->id);
});

test('the role mix is frozen once the baseline is submitted', function () {
    [$manager, $baseline, $first, , $version] = pricedBaseline();
    $developer = $version->roles()->where('name', 'Developer')->sole();
    $baseline->status = BaselineStatus::AwaitingApproval;
    $baseline->save();

    $this->actingAs($manager)
        ->put(route('baselines.commercials.update', $baseline), [
            'allocations' => [
                ['baseline_item_id' => $first->id, 'rate_card_role_id' => $developer->id, 'days' => '10'],
            ],
        ])
        ->assertForbidden();
});

test('members cannot price the baseline', function () {
    $member = User::factory()->role(UserRole::Member)->create();
    $baseline = Baseline::factory()->for($member->organization)->create();

    $this->actingAs($member)
        ->put(route('baselines.commercials.update', $baseline), ['allocations' => []])
        ->assertForbidden();
});
