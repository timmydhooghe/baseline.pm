<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the engagements index renders for members', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('engagements.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('engagements/index'));
});

test('the organization page lists the members of the current organization', function () {
    $user = User::factory()->create();
    User::factory()->count(2)->for($user->organization)->create();
    User::factory()->create();

    $this->actingAs($user)
        ->get(route('organization.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/show')
            ->where('organization.id', $user->organization_id)
            ->has('members', 3));
});

test('guests are redirected away from the app shell', function () {
    $this->get(route('engagements.index'))->assertRedirect(route('login'));
    $this->get(route('organization.show'))->assertRedirect(route('login'));
});

test('the stakeholder portal landing is publicly reachable', function () {
    $this->get(route('portal.welcome'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('portal/welcome'));
});
