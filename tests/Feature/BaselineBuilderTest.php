<?php

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\Engagement;
use App\Models\User;
use App\ValueObjects\Money;
use Inertia\Testing\AssertableInertia as Assert;

function baselineDetailsPayload(): array
{
    return [
        'commercial_model' => 'fixed_price',
        'contract_value' => '100000',
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-18',
        'execution_mode' => 'jira',
    ];
}

test('a delivery manager starts a baseline draft from the details step', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload())
        ->assertRedirect(route('engagements.baseline.show', $engagement));

    $baseline = Baseline::query()->sole();

    expect($baseline->version)->toBe(1)
        ->and($baseline->status)->toBe(BaselineStatus::Draft)
        ->and($baseline->engagement_id)->toBe($engagement->id)
        ->and($baseline->contract_value->amount)->toBe(10000000)
        ->and($baseline->start_date->toDateString())->toBe('2026-09-01')
        ->and($baseline->created_by)->toBe($manager->id)
        ->and($engagement->refresh()->status)->toBe(EngagementStatus::PreparingBaseline);
});

test('starting a baseline pins the current rate card version forever', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $roles = [['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)]];
    $manager->organization->publishRateCardVersion($roles);
    $pinned = $manager->organization->publishRateCardVersion($roles);

    $engagement = Engagement::factory()->for($manager->organization)->create();

    $this->actingAs($manager)->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload());

    $manager->organization->publishRateCardVersion($roles);

    expect(Baseline::query()->sole()->rate_card_version_id)->toBe($pinned->id);
});

test('an engagement cannot have two baselines in progress', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->status(EngagementStatus::PreparingBaseline)->create();
    Baseline::factory()->for($manager->organization)->for($engagement)->create();

    $this->actingAs($manager)
        ->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload())
        ->assertInvalid(['baseline']);
});

test('later baseline versions come from change requests, not the builder', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->status(EngagementStatus::Active)->create();
    Baseline::factory()->for($manager->organization)->for($engagement)->status(BaselineStatus::Approved)->create();

    $this->actingAs($manager)
        ->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload())
        ->assertInvalid(['baseline']);
});

test('members and portfolio viewers cannot start a baseline', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload())
        ->assertForbidden();
})->with([
    'member' => UserRole::Member,
    'portfolio viewer' => UserRole::PortfolioViewer,
]);

test('the baseline builder of another organization is not found', function () {
    $engagement = Engagement::factory()->create();
    $outsider = User::factory()->role(UserRole::DeliveryManager)->create();

    $this->actingAs($outsider)->get(route('engagements.baseline.show', $engagement))->assertNotFound();
    $this->actingAs($outsider)->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload())->assertNotFound();
});

test('the wizard renders the draft with its completeness checks', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->status(EngagementStatus::PreparingBaseline)->create();
    $baseline = Baseline::factory()->for($manager->organization)->for($engagement)->create();

    $this->actingAs($manager)
        ->get(route('engagements.baseline.show', $engagement))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/baseline')
            ->where('baseline.id', $baseline->id)
            ->where('baseline.version', 1)
            ->where('baseline.status', 'draft')
            ->has('baseline.checks', 5)
            ->where('can.manage', true));
});

test('the wizard renders the details step before a baseline exists', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->get(route('engagements.baseline.show', $engagement))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/baseline')
            ->where('baseline', null)
            ->where('can.manage', true));
});

test('details can be updated while drafting', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->patch(route('baselines.update', $baseline), [
            ...baselineDetailsPayload(),
            'contract_value' => '125000.50',
            'execution_mode' => 'mixed',
        ])
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    expect($baseline->refresh()->contract_value->amount)->toBe(12500050)
        ->and($baseline->execution_mode->value)->toBe('mixed');
});

test('details are frozen once the baseline is submitted', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->status(BaselineStatus::AwaitingApproval)->create();

    $this->actingAs($manager)
        ->patch(route('baselines.update', $baseline), baselineDetailsPayload())
        ->assertForbidden();
});

test('the end date cannot precede the start date', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('engagements.baseline.store', $engagement), [
            ...baselineDetailsPayload(),
            'start_date' => '2026-12-18',
            'end_date' => '2026-09-01',
        ])
        ->assertInvalid(['end_date']);
});

test('starting a baseline is recorded in the audit log', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();

    $this->actingAs($manager)->post(route('engagements.baseline.store', $engagement), baselineDetailsPayload());

    $baseline = Baseline::query()->sole();

    expect(
        AuditLog::query()
            ->where('subject_type', $baseline->getMorphClass())
            ->where('subject_id', $baseline->id)
            ->where('action', 'created')
            ->exists()
    )->toBeTrue();
});
