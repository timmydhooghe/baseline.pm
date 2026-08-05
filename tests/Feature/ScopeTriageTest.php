<?php

use App\Enums\BaselineItemType;
use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestOrigin;
use App\Enums\ChangeRequestStatus;
use App\Enums\EngagementStatus;
use App\Enums\EstimateUnit;
use App\Enums\UserRole;
use App\Enums\WorkItemState;
use App\Enums\WorkItemTriageStatus;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\RateCardRole;
use App\Models\RateCardVersion;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemWorklog;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/**
 * An active engagement executing against a baseline priced from a single-role
 * rate card (€600 cost / €1,000 sell per day), with one deliverable, one
 * upcoming milestone and a connected Jira integration. The baseline is
 * approved unless a test needs the draft to add allocations first.
 *
 * @return array{user: User, organization: Organization, engagement: Engagement, baseline: Baseline, rateCardVersion: RateCardVersion, deliverable: BaselineItem, milestone: BaselineItem, connection: IntegrationConnection}
 */
function triageSetup(UserRole $role = UserRole::DeliveryManager, bool $approveBaseline = true): array
{
    $user = User::factory()->role($role)->create();
    $organization = $user->organization;

    $rateCardVersion = RateCardVersion::factory()->for($organization)->create();
    RateCardRole::factory()->for($organization)->create([
        'rate_card_version_id' => $rateCardVersion->id,
        'cost_per_day' => Money::fromCents(60000),
        'sell_per_day' => Money::fromCents(100000),
    ]);

    $engagement = Engagement::factory()->for($organization)->status(EngagementStatus::Active)->create();
    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'rate_card_version_id' => $rateCardVersion->id,
    ]);
    $deliverable = BaselineItem::factory()
        ->for($organization)
        ->for($baseline)
        ->type(BaselineItemType::Deliverable)
        ->create(['title' => 'Checkout flow']);
    $milestone = BaselineItem::factory()->for($organization)->for($baseline)->create([
        'type' => BaselineItemType::Milestone,
        'position' => 2,
        'title' => 'Go-live',
        'baseline_date' => now()->addDays(14),
        'payment_trigger' => 'on acceptance',
    ]);
    $connection = IntegrationConnection::factory()->for($organization)->for($engagement)->create();

    if ($approveBaseline) {
        approveTriageBaseline($baseline);
    }

    return [
        'user' => $user,
        'organization' => $organization,
        'engagement' => $engagement,
        'baseline' => $baseline,
        'rateCardVersion' => $rateCardVersion,
        'deliverable' => $deliverable,
        'milestone' => $milestone,
        'connection' => $connection,
    ];
}

function approveTriageBaseline(Baseline $baseline): void
{
    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();
}

test('the triage inbox surfaces unmapped untriaged work with age, logged time, derived cost and potential price', function () {
    Queue::fake();

    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'deliverable' => $deliverable, 'connection' => $connection] = triageSetup();

    $drift = WorkItem::factory()->jira()
        ->for($organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create(['estimate_value' => 2 * 8 * 3600, 'state' => WorkItemState::Todo]);
    $drift->forceFill(['created_at' => now()->subDays(10)])->save();

    WorkItemWorklog::factory()->for($organization)->create([
        'work_item_id' => $drift->id,
        'seconds' => 4 * 3600,
        'logged_on' => now()->subDays(3),
    ]);

    $mapped = WorkItem::factory()->for($organization)->for($engagement)->create();
    $mapped->linkTo($deliverable, $user);

    $triaged = WorkItem::factory()->for($organization)->for($engagement)->create();
    $triaged->triage(WorkItemTriageStatus::Dismissed, $user);

    $this->actingAs($user)
        ->get(route('engagements.triage.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/triage')
            ->has('inbox', 1)
            ->where('inbox.0.id', $drift->id)
            ->where('inbox.0.ageDays', 10)
            ->where('inbox.0.logged', '4h')
            ->where('inbox.0.effortDays', 2)
            ->where('inbox.0.cost.amount', 120000)
            ->where('inbox.0.price.amount', 200000)
            ->where('inbox.0.suggestedDeliverable.id', $deliverable->id)
            ->where('inbox.0.timelineImpact.milestone', 'Go-live')
            ->where('inbox.0.timelineImpact.daysUntil', 14)
            ->where('pricing.available', true)
            ->where('position.unbilledRisk.count', 1)
            ->where('position.unbilledRisk.price.amount', 200000)
            ->etc());
});

test('drift pricing blends the baseline role mix weighted by allocated days', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'baseline' => $baseline, 'rateCardVersion' => $rateCardVersion, 'deliverable' => $deliverable] = triageSetup(approveBaseline: false);

    $senior = RateCardRole::factory()->for($organization)->create([
        'rate_card_version_id' => $rateCardVersion->id,
        'cost_per_day' => Money::fromCents(100000),
        'sell_per_day' => Money::fromCents(140000),
        'position' => 2,
    ]);
    $developer = $rateCardVersion->roles()->where('id', '!=', $senior->id)->firstOrFail();

    BaselineAllocation::factory()->for($organization)->create([
        'baseline_id' => $baseline->id,
        'baseline_item_id' => $deliverable->id,
        'rate_card_role_id' => $developer->id,
        'days' => '3',
    ]);
    BaselineAllocation::factory()->for($organization)->create([
        'baseline_id' => $baseline->id,
        'baseline_item_id' => null,
        'rate_card_role_id' => $senior->id,
        'days' => '1',
    ]);
    approveTriageBaseline($baseline);

    WorkItem::factory()->for($organization)->for($engagement)->create([
        'estimate_value' => 1,
        'estimate_unit' => EstimateUnit::Days,
    ]);

    // Blended: cost (3×600 + 1×1000)/4 = €700/day, sell (3×1000 + 1×1400)/4 = €1,100/day.
    $this->actingAs($user)
        ->get(route('engagements.triage.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pricing.costPerDay.amount', 70000)
            ->where('pricing.sellPerDay.amount', 110000)
            ->where('inbox.0.cost.amount', 70000)
            ->where('inbox.0.price.amount', 110000)
            ->etc());
});

test('a points estimate without logged time surfaces unpriced instead of inventing a conversion', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'connection' => $connection] = triageSetup();

    WorkItem::factory()->linear()
        ->for($organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create(['state' => WorkItemState::Todo]);

    $this->actingAs($user)
        ->get(route('engagements.triage.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inbox.0.effortDays', null)
            ->where('inbox.0.cost', null)
            ->where('inbox.0.price', null)
            ->where('position.unbilledRisk.count', 1)
            ->where('position.unbilledRisk.unpriced', 1)
            ->where('position.unbilledRisk.price.amount', 0)
            ->etc());
});

test('classifying as existing scope requires a deliverable, maps the item and records who decided when', function () {
    Queue::fake();

    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'deliverable' => $deliverable] = triageSetup();

    $item = WorkItem::factory()->for($organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), ['classification' => 'existing_scope'])
        ->assertInvalid(['baseline_item_id']);

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), [
            'classification' => 'existing_scope',
            'baseline_item_id' => $deliverable->id,
        ])
        ->assertRedirect(route('engagements.triage.show', $engagement));

    $item->refresh();

    expect($item->triage_status)->toBe(WorkItemTriageStatus::ExistingScope)
        ->and($item->triaged_by)->toBe($user->id)
        ->and($item->triaged_at)->not->toBeNull()
        ->and($item->link?->baseline_item_id)->toBe($deliverable->id)
        ->and(AuditLog::query()->where('action', 'work_item.triaged')->count())->toBe(1);
});

test('classifying as a potential change drafts a change request pre-filled from the item', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'connection' => $connection] = triageSetup();

    $item = WorkItem::factory()->jira()
        ->for($organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create(['estimate_value' => 2 * 8 * 3600]);

    WorkItemWorklog::factory()->for($organization)->create([
        'work_item_id' => $item->id,
        'seconds' => 4 * 3600,
        'logged_on' => '2026-07-30',
    ]);

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), ['classification' => 'potential_change'])
        ->assertRedirect();

    $changeRequest = ChangeRequest::query()->sole();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::Draft)
        ->and($changeRequest->work_item_id)->toBe($item->id)
        ->and($changeRequest->engagement_id)->toBe($engagement->id)
        ->and($changeRequest->origin)->toBe(ChangeRequestOrigin::Drift)
        ->and($changeRequest->title)->toContain($item->title)
        ->and($changeRequest->estimated_days)->toBe(2.0)
        ->and($changeRequest->logged_seconds)->toBe(4 * 3600)
        ->and($changeRequest->work_started_at?->toDateString())->toBe('2026-07-30')
        ->and($changeRequest->flagsContractualBreach())->toBeTrue()
        ->and($changeRequest->created_by)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'change_request.drafted')->count())->toBe(1);

    // Re-triaging reuses the draft instead of stacking a second one.
    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), ['classification' => 'potential_change'])
        ->assertRedirect();

    expect(ChangeRequest::query()->count())->toBe(1);
});

test('excluding work as operational demands an explanation that stays on record', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement] = triageSetup();

    $item = WorkItem::factory()->for($organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), ['classification' => 'operational'])
        ->assertInvalid(['note']);

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), [
            'classification' => 'operational',
            'note' => 'Internal CI maintenance, not client scope.',
        ])
        ->assertRedirect();

    $item->refresh();

    expect($item->triage_status)->toBe(WorkItemTriageStatus::Operational)
        ->and($item->triage_note)->toBe('Internal CI maintenance, not client scope.')
        ->and($item->link)->toBeNull()
        ->and($engagement->driftWorkItems()->count())->toBe(0);
});

test('a dismissed item leaves the queue but its classification stays on record', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement] = triageSetup();

    $item = WorkItem::factory()->for($organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), ['classification' => 'dismissed'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('engagements.triage.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('inbox', 0)
            ->has('triaged', 1)
            ->where('triaged.0.classification', 'dismissed')
            ->where('triaged.0.triagedByName', $user->name)
            ->etc());
});

test('work already in motion without an approved change request is flagged as contractual breach risk', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement] = triageSetup();

    $withWorklog = WorkItem::factory()->for($organization)->for($engagement)->create();
    $withWorklog->forceFill(['created_at' => now()->subDays(3)])->save();
    WorkItemWorklog::factory()->for($organization)->create([
        'work_item_id' => $withWorklog->id,
        'seconds' => 2 * 3600,
        'logged_on' => now()->subDays(2),
    ]);

    $inProgress = WorkItem::factory()->for($organization)->for($engagement)
        ->inState(WorkItemState::InProgress)
        ->create();
    $inProgress->forceFill(['created_at' => now()->subDays(2)])->save();

    $untouched = WorkItem::factory()->for($organization)->for($engagement)->create();

    $this->actingAs($user)
        ->get(route('engagements.triage.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inbox.0.id', $withWorklog->id)
            ->where('inbox.0.breachRisk', true)
            ->where('inbox.1.id', $inProgress->id)
            ->where('inbox.1.breachRisk', true)
            ->where('inbox.2.id', $untouched->id)
            ->where('inbox.2.breachRisk', false)
            ->etc());
});

test('unbilled risk aggregates the potential price of unresolved drift and shrinks as it is triaged', function () {
    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'baseline' => $baseline] = triageSetup();

    [$first, $second] = WorkItem::factory()
        ->count(2)
        ->for($organization)
        ->for($engagement)
        ->create(['estimate_value' => 1, 'estimate_unit' => EstimateUnit::Days]);

    $this->actingAs($user)
        ->get(route('engagements.work.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('position.contracted.amount', $baseline->contract_value->amount)
            ->where('position.baselineVersion', 1)
            ->where('position.unbilledRisk.count', 2)
            ->where('position.unbilledRisk.price.amount', 200000)
            ->etc());

    $first->triage(WorkItemTriageStatus::Dismissed, $user);

    $risk = $engagement->unbilledRisk();

    expect($risk['count'])->toBe(1)
        ->and($risk['price']->amount)->toBe(100000)
        ->and($risk['cost']->amount)->toBe(60000);
});

test('unlinking an item classified as existing scope sends it back to the triage inbox', function () {
    Queue::fake();

    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'deliverable' => $deliverable] = triageSetup();

    $item = WorkItem::factory()->for($organization)->for($engagement)->create();
    $item->triage(WorkItemTriageStatus::ExistingScope, $user, $deliverable);

    expect($engagement->driftWorkItems()->count())->toBe(0);

    $item->refresh()->unlink($user);

    expect($item->refresh()->triage_status)->toBeNull()
        ->and($item->triaged_by)->toBeNull()
        ->and($engagement->driftWorkItems()->count())->toBe(1);
});

test('an item that is already mapped cannot be triaged as drift', function () {
    Queue::fake();

    ['user' => $user, 'organization' => $organization, 'engagement' => $engagement, 'deliverable' => $deliverable] = triageSetup();

    $item = WorkItem::factory()->for($organization)->for($engagement)->create();
    $item->linkTo($deliverable, $user);

    $this->actingAs($user)
        ->post(route('work-items.triage.store', $item), ['classification' => 'dismissed'])
        ->assertInvalid(['classification']);

    expect($item->refresh()->triage_status)->toBeNull();
});

test('classifying drift is a governance call reserved for managers', function () {
    ['user' => $member, 'organization' => $organization, 'engagement' => $engagement] = triageSetup(UserRole::Member);

    $item = WorkItem::factory()->for($organization)->for($engagement)->create();

    $this->actingAs($member)
        ->post(route('work-items.triage.store', $item), ['classification' => 'dismissed'])
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('engagements.triage.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.triage', false)
            ->etc());
});
