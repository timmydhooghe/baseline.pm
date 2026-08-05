<?php

use App\Enums\EngagementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a manager starts an engagement as a draft', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('engagements.store'), [
            'name' => 'ERP rollout',
            'customer_id' => $customer->id,
        ])
        ->assertRedirect();

    $engagement = Engagement::query()->where('name', 'ERP rollout')->sole();

    expect($engagement->status)->toBe(EngagementStatus::Draft)
        ->and($engagement->customer_id)->toBe($customer->id)
        ->and($engagement->organization_id)->toBe($manager->organization_id);
});

test('an engagement cannot be created for another organization\'s customer', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $foreignCustomer = Customer::factory()->create();

    $this->actingAs($manager)
        ->post(route('engagements.store'), [
            'name' => 'Sneaky',
            'customer_id' => $foreignCustomer->id,
        ])
        ->assertSessionHasErrors('customer_id');
});

test('members cannot start engagements', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->post(route('engagements.store'), ['name' => 'Nope', 'customer_id' => 'irrelevant'])
        ->assertForbidden();
});

test('an engagement walks the full lifecycle to archived', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();

    $path = [
        EngagementStatus::PreparingBaseline,
        EngagementStatus::AwaitingBaselineApproval,
        EngagementStatus::Active,
        EngagementStatus::AwaitingFinalAcceptance,
        EngagementStatus::Completed,
        EngagementStatus::Archived,
    ];

    foreach ($path as $target) {
        $this->actingAs($manager)
            ->post(route('engagements.transition', $engagement), ['status' => $target->value])
            ->assertRedirect(route('engagements.show', $engagement));

        expect($engagement->refresh()->status)->toBe($target);
    }

    expect(AuditLog::query()
        ->where('action', 'engagement.transitioned')
        ->where('subject_id', $engagement->id)
        ->count())->toBe(count($path));
});

test('lifecycle steps cannot be skipped', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'active'])
        ->assertSessionHasErrors('status');

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Draft);
});

test('members and portfolio viewers cannot transition engagements', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('engagements.transition', $engagement), ['status' => 'preparing_baseline'])
        ->assertForbidden();
})->with([
    'member' => UserRole::Member,
    'portfolio viewer' => UserRole::PortfolioViewer,
]);

test('archived engagements accept no further transitions', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()
        ->for($manager->organization)
        ->status(EngagementStatus::Archived)
        ->create();

    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'draft'])
        ->assertSessionHasErrors('status');
});

test('archived engagements are read-only at the model level', function () {
    $engagement = Engagement::factory()->status(EngagementStatus::Archived)->create();

    $engagement->name = 'New name';

    expect(fn () => $engagement->save())
        ->toThrow(LogicException::class, 'Archived engagements are read-only.');
});

test('transitionTo refuses moves the state machine does not allow', function () {
    $engagement = Engagement::factory()->create();

    expect(fn () => $engagement->transitionTo(EngagementStatus::Completed))
        ->toThrow(LogicException::class);
});

test('every role can view the engagement portfolio', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->get(route('engagements.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/index')
            ->has('engagements', 1));

    $this->actingAs($user)
        ->get(route('engagements.show', $engagement))
        ->assertSuccessful();
})->with([
    'owner' => UserRole::Owner,
    'delivery manager' => UserRole::DeliveryManager,
    'commercial manager' => UserRole::CommercialManager,
    'member' => UserRole::Member,
    'portfolio viewer' => UserRole::PortfolioViewer,
]);

test('archived engagements stay searchable', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    Engagement::factory()
        ->for($manager->organization)
        ->status(EngagementStatus::Archived)
        ->create(['name' => 'Legacy platform migration']);
    Engagement::factory()->for($manager->organization)->create(['name' => 'Fresh start']);

    $this->actingAs($manager)
        ->get(route('engagements.index', ['q' => 'legacy']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('engagements', 1)
            ->where('engagements.0.status', 'archived'));
});

test('the search also matches customer names', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create(['name' => 'Nordwind BV']);
    Engagement::factory()->for($manager->organization)->for($customer)->create(['name' => 'Rollout']);
    Engagement::factory()->for($manager->organization)->create(['name' => 'Other work']);

    $this->actingAs($manager)
        ->get(route('engagements.index', ['q' => 'nordwind']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('engagements', 1)
            ->where('engagements.0.name', 'Rollout'));
});

test('engagements of other organizations are hidden and not found', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $foreignEngagement = Engagement::factory()->create();

    $this->actingAs($manager)
        ->get(route('engagements.index'))
        ->assertInertia(fn (Assert $page) => $page->has('engagements', 0));

    $this->actingAs($manager)
        ->get(route('engagements.show', $foreignEngagement))
        ->assertNotFound();
});

test('only managers may follow the customer link on an engagement', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->create();
    $member = User::factory()->for($manager->organization)->role(UserRole::Member)->create();

    $this->actingAs($manager)
        ->get(route('engagements.show', $engagement))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewCustomer', true));

    $this->actingAs($member)
        ->get(route('engagements.show', $engagement))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewCustomer', false));
});
