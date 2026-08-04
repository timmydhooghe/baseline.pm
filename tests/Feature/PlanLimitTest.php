<?php

use App\Enums\EngagementStatus;
use App\Enums\Plan;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\Stakeholder;
use App\Models\User;

test('the solo plan allows a single active engagement', function () {
    $organization = Organization::factory()->create(['plan' => Plan::Solo]);
    $manager = User::factory()->for($organization)->role(UserRole::DeliveryManager)->create();
    $customer = Customer::factory()->for($organization)->create();
    Engagement::factory()->for($organization)->for($customer)->create();

    $this->actingAs($manager)
        ->post(route('engagements.store'), [
            'name' => 'One too many',
            'customer_id' => $customer->id,
        ])
        ->assertSessionHasErrors('plan');

    expect($organization->engagements()->count())->toBe(1);
});

test('archived engagements free up their plan slot', function () {
    $organization = Organization::factory()->create(['plan' => Plan::Solo]);
    $manager = User::factory()->for($organization)->role(UserRole::DeliveryManager)->create();
    $customer = Customer::factory()->for($organization)->create();
    Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::Archived)
        ->create();

    $this->actingAs($manager)
        ->post(route('engagements.store'), [
            'name' => 'Second wind',
            'customer_id' => $customer->id,
        ])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($organization->activeEngagementCount())->toBe(1)
        ->and($organization->engagements()->count())->toBe(2);
});

test('drafts and completed engagements occupy a plan slot', function () {
    $organization = Organization::factory()->create(['plan' => Plan::Solo]);
    Engagement::factory()->for($organization)->status(EngagementStatus::Completed)->create();

    expect($organization->hasReachedActiveEngagementLimit())->toBeTrue();
});

test('the firm plan has no engagement limit', function () {
    $organization = Organization::factory()->create(['plan' => Plan::Firm]);
    $manager = User::factory()->for($organization)->role(UserRole::DeliveryManager)->create();
    $customer = Customer::factory()->for($organization)->create();
    Engagement::factory()->for($organization)->for($customer)->count(3)->create();

    $this->actingAs($manager)
        ->post(route('engagements.store'), [
            'name' => 'Number four',
            'customer_id' => $customer->id,
        ])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($organization->hasReachedActiveEngagementLimit())->toBeFalse();
});

test('external stakeholders never consume plan capacity', function () {
    $organization = Organization::factory()->create(['plan' => Plan::Solo]);
    $customer = Customer::factory()->for($organization)->create();
    Stakeholder::factory()->for($organization)->for($customer)->count(3)->create();

    expect($organization->hasReachedActiveEngagementLimit())->toBeFalse()
        ->and($organization->activeEngagementCount())->toBe(0);
});
