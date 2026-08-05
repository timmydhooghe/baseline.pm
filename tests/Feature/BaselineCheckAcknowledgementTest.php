<?php

use App\Enums\BaselineItemType;
use App\Enums\UserRole;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\User;

/**
 * A draft baseline with one bare deliverable: deliverable_details fails
 * (no owner, no value) while milestone_details passes (no milestones).
 *
 * @return array{user: User, baseline: Baseline}
 */
function acknowledgementSetup(): array
{
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($user->organization)->create();
    BaselineItem::factory()
        ->for($user->organization)
        ->for($baseline)
        ->type(BaselineItemType::Deliverable)
        ->create();

    return ['user' => $user, 'baseline' => $baseline];
}

test('a passing completeness check cannot be acknowledged in advance', function () {
    ['user' => $user, 'baseline' => $baseline] = acknowledgementSetup();

    $this->actingAs($user)
        ->post(route('baselines.checks.acknowledge', $baseline), ['check' => 'milestone_details'])
        ->assertInvalid(['check']);

    expect($baseline->refresh()->acknowledged_checks)->toBe([]);
});

test('acknowledging a failing check records who accepted which failure', function () {
    ['user' => $user, 'baseline' => $baseline] = acknowledgementSetup();

    $this->actingAs($user)
        ->post(route('baselines.checks.acknowledge', $baseline), ['check' => 'deliverable_details'])
        ->assertRedirect();

    $check = collect($baseline->refresh()->completenessChecks())->firstWhere('key', 'deliverable_details');

    expect($check)->not->toBeNull()
        ->and($check['passed'])->toBeFalse()
        ->and($check['acknowledged'])->toBeTrue()
        ->and($check['acknowledgedBy'])->toBe($user->name);
});

test('an acknowledgement stops counting when the failure it accepted changes', function () {
    ['user' => $user, 'baseline' => $baseline] = acknowledgementSetup();

    $this->actingAs($user)
        ->post(route('baselines.checks.acknowledge', $baseline), ['check' => 'deliverable_details'])
        ->assertRedirect();

    /*
     * A second incomplete deliverable changes what the check is failing on
     * — the earlier acknowledgement accepted a different failure.
     */
    BaselineItem::factory()
        ->for($user->organization)
        ->for($baseline)
        ->type(BaselineItemType::Deliverable)
        ->create(['position' => 2]);

    $check = collect($baseline->refresh()->completenessChecks())->firstWhere('key', 'deliverable_details');

    expect($check)->not->toBeNull()
        ->and($check['acknowledged'])->toBeFalse()
        ->and($check['acknowledgedBy'])->toBeNull();
});
