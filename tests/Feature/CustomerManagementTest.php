<?php

use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Stakeholder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a manager creates a customer record', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)
        ->post(route('customers.store'), ['name' => 'Acme Industries'])
        ->assertRedirect();

    $customer = Customer::query()->where('name', 'Acme Industries')->sole();

    expect($customer->organization_id)->toBe($manager->organization_id);
});

test('customer names are unique per organization, not globally', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    Customer::factory()->for($manager->organization)->create(['name' => 'Acme Industries']);
    Customer::factory()->create(['name' => 'Nordwind BV']);

    $this->actingAs($manager)
        ->post(route('customers.store'), ['name' => 'Acme Industries'])
        ->assertSessionHasErrors('name');

    $this->actingAs($manager)
        ->post(route('customers.store'), ['name' => 'Nordwind BV'])
        ->assertSessionDoesntHaveErrors();
});

test('members and portfolio viewers cannot manage customers', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)->get(route('customers.index'))->assertForbidden();
    $this->actingAs($user)->post(route('customers.store'), ['name' => 'Acme'])->assertForbidden();
})->with([
    'member' => UserRole::Member,
    'portfolio viewer' => UserRole::PortfolioViewer,
]);

test('the customer index lists only the current organization', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    Customer::factory()->for($manager->organization)->count(2)->create();
    Customer::factory()->create();

    $this->actingAs($manager)
        ->get(route('customers.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/index')
            ->has('customers', 2)
            ->where('can.manage', true));
});

test('a customer of another organization is not found', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $foreignCustomer = Customer::factory()->create();

    $this->actingAs($manager)
        ->get(route('customers.show', $foreignCustomer))
        ->assertNotFound();
});

test('a manager renames a customer', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->patch(route('customers.update', $customer), ['name' => 'Renamed NV'])
        ->assertRedirect(route('customers.show', $customer));

    expect($customer->refresh()->name)->toBe('Renamed NV');
});

test('deleting a customer removes its stakeholders', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create();
    $stakeholder = Stakeholder::factory()->for($manager->organization)->for($customer)->create();

    $this->actingAs($manager)
        ->delete(route('customers.destroy', $customer))
        ->assertRedirect(route('customers.index'));

    $this->assertModelMissing($customer);
    $this->assertModelMissing($stakeholder);
});

test('a customer with engagements cannot be deleted', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create();
    Engagement::factory()->for($manager->organization)->for($customer)->create();

    $this->actingAs($manager)
        ->delete(route('customers.destroy', $customer))
        ->assertSessionHasErrors('customer');

    $this->assertModelExists($customer);
});

test('a manager adds a stakeholder to a customer', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('customers.stakeholders.store', $customer), [
            'name' => 'Petra Molnar',
            'email' => 'pm@acme.test',
            'role' => 'project_manager',
        ])
        ->assertRedirect(route('customers.show', $customer));

    $stakeholder = Stakeholder::query()->where('email', 'pm@acme.test')->sole();

    expect($stakeholder->customer_id)->toBe($customer->id)
        ->and($stakeholder->organization_id)->toBe($manager->organization_id)
        ->and($stakeholder->role)->toBe(StakeholderRole::ProjectManager);
});

test('a stakeholder email is unique within the organization', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $customer = Customer::factory()->for($manager->organization)->create();
    Stakeholder::factory()->for($manager->organization)->for($customer)->create(['email' => 'pm@acme.test']);

    $this->actingAs($manager)
        ->post(route('customers.stakeholders.store', $customer), [
            'name' => 'Duplicate',
            'email' => 'pm@acme.test',
            'role' => 'viewer',
        ])
        ->assertSessionHasErrors('email');
});

test('a manager changes a stakeholder role', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $stakeholder = Stakeholder::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->patch(route('stakeholders.update', $stakeholder), [
            'name' => $stakeholder->name,
            'email' => $stakeholder->email,
            'role' => 'approver',
        ])
        ->assertRedirect(route('customers.show', $stakeholder->customer_id));

    expect($stakeholder->refresh()->role)->toBe(StakeholderRole::Approver);
});

test('a manager removes a stakeholder', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();
    $stakeholder = Stakeholder::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->delete(route('stakeholders.destroy', $stakeholder))
        ->assertRedirect(route('customers.show', $stakeholder->customer_id));

    $this->assertModelMissing($stakeholder);
});
