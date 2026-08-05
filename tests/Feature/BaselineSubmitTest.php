<?php

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineItem;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Validation\ValidationException;

/**
 * A manager with a baseline that passes every completeness check: two valued
 * deliverables summing to the contract, a dated milestone with a payment
 * trigger, and a role mix (incl. delivery management) at the pinned rates.
 * All free text is fixed so leakage assertions can scan the whole payload.
 *
 * @return array{0: User, 1: Baseline, 2: Engagement}
 */
function submittableBaseline(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $organization = $manager->organization;

    $version = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
    ]);
    $developer = $version->roles()->sole();

    $customer = Customer::factory()->for($organization)->create(['name' => 'Acme Industries']);
    $engagement = Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::PreparingBaseline)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'rate_card_version_id' => $version->id,
        'contract_value' => Money::fromCents(2000000),
    ]);

    $owner = User::factory()->for($organization)->create(['name' => 'Delivery Owner']);

    $first = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Checkout flow', 'description' => 'The full purchase funnel.', 'value' => Money::fromCents(1200000), 'position' => 1, 'owner_id' => $owner->id,
    ]);
    $second = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Reporting pack', 'description' => 'Weekly figures for finance.', 'value' => Money::fromCents(800000), 'position' => 2, 'owner_id' => $owner->id,
    ]);
    BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live', 'description' => null, 'position' => 1,
    ]);

    foreach ([[$first->id, '10'], [$second->id, '5'], [null, '3']] as [$itemId, $days]) {
        BaselineAllocation::factory()->for($organization)->for($baseline)->create([
            'baseline_item_id' => $itemId,
            'rate_card_role_id' => $developer->id,
            'days' => $days,
        ]);
    }

    return [$manager, $baseline, $engagement];
}

function acmeApprover(Baseline $baseline): Stakeholder
{
    return Stakeholder::factory()
        ->for($baseline->organization)
        ->for($baseline->engagement->customer)
        ->role(StakeholderRole::Approver)
        ->create(['name' => 'Anders Vik']);
}

test('a complete baseline submits and freezes twin review snapshots', function () {
    [$manager, $baseline, $engagement] = submittableBaseline();

    $this->actingAs($manager)
        ->post(route('baselines.submit', $baseline))
        ->assertRedirect(route('engagements.baseline.show', $engagement));

    $baseline->refresh();

    expect($baseline->status)->toBe(BaselineStatus::AwaitingApproval)
        ->and($baseline->submitted_at)->not->toBeNull()
        ->and($engagement->refresh()->status)->toBe(EngagementStatus::AwaitingBaselineApproval)
        ->and($baseline->reviewSnapshot?->payload['kind'])->toBe('internal_review')
        ->and($baseline->customerSnapshot?->payload['kind'])->toBe('customer_review')
        ->and($baseline->reviewSnapshot?->verifyIntegrity())->toBeTrue()
        ->and($baseline->customerSnapshot?->verifyIntegrity())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'baseline.submitted')->where('subject_id', $baseline->id)->exists())->toBeTrue();
});

test('the internal snapshot locks the derived cost budget and planned margin', function () {
    [$manager, $baseline] = submittableBaseline();

    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    $commercials = $baseline->refresh()->reviewSnapshot?->payload['commercials'];

    // 18 developer days at €450 cost: €8,100 budget against a €20,000 contract.
    expect($commercials['rate_card_version'])->toBe(1)
        ->and($commercials['cost_budget']['amount'])->toBe(810000)
        ->and($commercials['delivery_management_cost']['amount'])->toBe(135000)
        ->and($commercials['planned_margin']['amount'])->toBe(1190000)
        ->and($commercials['allocations'])->toHaveCount(3);
});

test('the customer snapshot never contains cost, rate or margin data', function () {
    [$manager, $baseline] = submittableBaseline();

    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    $payload = $baseline->refresh()->customerSnapshot?->payload;
    $json = mb_strtolower(json_encode($payload, JSON_THROW_ON_ERROR));

    expect($json)->not->toContain('cost')
        ->and($json)->not->toContain('margin')
        ->and($json)->not->toContain('rate')
        ->and($json)->not->toContain('allocation')
        ->and($payload['items'][0]['value']['amount'])->toBe(1200000)
        ->and($payload['baseline']['contract_value']['amount'])->toBe(2000000);
});

test('submission is blocked while completeness warnings are unresolved', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create([
        'contract_value' => Money::fromCents(2000000),
    ]);

    $this->actingAs($manager)
        ->post(route('baselines.submit', $baseline))
        ->assertInvalid(['checks']);

    expect($baseline->refresh()->status)->toBe(BaselineStatus::Draft)
        ->and(Snapshot::query()->where('subject_id', $baseline->id)->count())->toBe(0);
});

test('acknowledged warnings unblock submission and are frozen into the snapshot', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create([
        'contract_value' => Money::fromCents(2000000),
    ]);

    $this->actingAs($manager)
        ->post(route('baselines.checks.acknowledge', $baseline), ['check' => 'values_match_contract'])
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    $this->actingAs($manager)->post(route('baselines.submit', $baseline))->assertSessionHasNoErrors();

    $checks = collect($baseline->refresh()->reviewSnapshot?->payload['completeness']['checks']);
    $acknowledged = $checks->firstWhere('key', 'values_match_contract');

    expect($baseline->status)->toBe(BaselineStatus::AwaitingApproval)
        ->and($acknowledged['passed'])->toBeFalse()
        ->and($acknowledged['acknowledged'])->toBeTrue()
        ->and($acknowledged['acknowledgedBy'])->toBe($manager->name);
});

test('unknown completeness checks cannot be acknowledged', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.checks.acknowledge', $baseline), ['check' => 'imaginary_check'])
        ->assertInvalid(['check']);
});

test('members cannot submit a baseline', function () {
    $member = User::factory()->role(UserRole::Member)->create();
    $baseline = Baseline::factory()->for($member->organization)->create();

    $this->actingAs($member)->post(route('baselines.submit', $baseline))->assertForbidden();
});

test('submission freezes the latest committed draft state, not cached reads', function () {
    [$manager, $baseline] = submittableBaseline();

    // Warm this instance's relation cache the way a long-lived request would.
    $baseline->completenessChecks();

    // Another editor commits a rename through a different model instance.
    BaselineItem::query()->where('title', 'Checkout flow')->sole()->update(['title' => 'Checkout journey']);

    $baseline->submitForApproval($manager);

    $titles = collect($baseline->reviewSnapshot?->payload['items'] ?? [])->pluck('title');

    expect($titles)->toContain('Checkout journey')
        ->and($titles)->not->toContain('Checkout flow');
});

test('a stale editor cannot mutate a baseline that left draft', function () {
    [$manager, $baseline] = submittableBaseline();

    $stale = Baseline::query()->findOrFail($baseline->id);

    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    expect($stale->status)->toBe(BaselineStatus::Draft)
        ->and(fn () => $stale->mutateAsDraft(fn () => null))->toThrow(ValidationException::class);
});

test('a submitted baseline is frozen at the model level', function () {
    [$manager, $baseline] = submittableBaseline();
    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    expect(fn () => $baseline->refresh()->update(['contract_value' => Money::fromCents(1)]))
        ->toThrow(LogicException::class, 'A submitted baseline is frozen while it awaits approval.');
});

test('approving the submission locks baseline v1 and activates the engagement', function () {
    [$manager, $baseline, $engagement] = submittableBaseline();
    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    $baseline->refresh()->approve(acmeApprover($baseline), 'Looks good.');

    $entry = AuditLog::query()->where('action', 'baseline.approved')->where('subject_id', $baseline->id)->sole();

    expect($baseline->status)->toBe(BaselineStatus::Approved)
        ->and($baseline->approved_at)->not->toBeNull()
        ->and($engagement->refresh()->status)->toBe(EngagementStatus::Active)
        ->and($entry->payload['approved_by'])->toBe('Anders Vik')
        ->and($entry->payload['comment'])->toBe('Looks good.');
});

test('an approved baseline is immutable forever', function () {
    [$manager, $baseline] = submittableBaseline();
    $this->actingAs($manager)->post(route('baselines.submit', $baseline));
    $baseline->refresh()->approve(acmeApprover($baseline));

    expect(fn () => $baseline->update(['contract_value' => Money::fromCents(1)]))
        ->toThrow(LogicException::class, 'Approved baselines are immutable; changes go through a change request.');

    expect(fn () => $baseline->delete())
        ->toThrow(LogicException::class, 'Submitted and approved baselines cannot be deleted.');
});

test('rejection returns the baseline to draft and preserves the snapshots', function () {
    [$manager, $baseline, $engagement] = submittableBaseline();
    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    $firstReviewSnapshotId = $baseline->refresh()->review_snapshot_id;

    $baseline->returnToDraft('rejected', acmeApprover($baseline), 'Values need rework.');

    expect($baseline->status)->toBe(BaselineStatus::Draft)
        ->and($engagement->refresh()->status)->toBe(EngagementStatus::PreparingBaseline)
        ->and(Snapshot::query()->where('subject_id', $baseline->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'baseline.returned_to_draft')->where('subject_id', $baseline->id)->sole()->payload['reason'])->toBe('rejected');

    // The draft reopens for editing and resubmission freezes fresh snapshots.
    $baseline->update(['contract_value' => Money::fromCents(2500000)]);
    $baseline->acknowledgeCheck('values_match_contract', $manager);

    $this->actingAs($manager)->post(route('baselines.submit', $baseline))->assertSessionHasNoErrors();

    expect(Snapshot::query()->where('subject_id', $baseline->id)->count())->toBe(4)
        ->and($baseline->refresh()->review_snapshot_id)->not->toBe($firstReviewSnapshotId);
});

test('moving the engagement to active approves the submitted baseline', function () {
    [$manager, $baseline, $engagement] = submittableBaseline();
    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'active'])
        ->assertRedirect(route('engagements.show', $engagement));

    expect($baseline->refresh()->status)->toBe(BaselineStatus::Approved)
        ->and($engagement->refresh()->status)->toBe(EngagementStatus::Active);
});

test('moving the engagement back to preparing withdraws the submission', function () {
    [$manager, $baseline, $engagement] = submittableBaseline();
    $this->actingAs($manager)->post(route('baselines.submit', $baseline));

    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'preparing_baseline'])
        ->assertRedirect(route('engagements.show', $engagement));

    expect($baseline->refresh()->status)->toBe(BaselineStatus::Draft)
        ->and($engagement->refresh()->status)->toBe(EngagementStatus::PreparingBaseline)
        ->and(AuditLog::query()->where('action', 'baseline.returned_to_draft')->sole()->payload['reason'])->toBe('withdrawn');
});

test('an engagement will not await approval without a submitted baseline', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->status(EngagementStatus::PreparingBaseline)->create();
    Baseline::factory()->for($manager->organization)->for($engagement)->create();

    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'awaiting_baseline_approval'])
        ->assertInvalid(['status']);

    expect($engagement->refresh()->status)->toBe(EngagementStatus::PreparingBaseline);
});
