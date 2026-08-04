<?php

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Support\Facades\Context;

test('queries on tenant models are scoped to the current organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $stakeholderA = Stakeholder::factory()->for($organizationA)->create();
    Stakeholder::factory()->for($organizationB)->create();

    Context::add('organization_id', $organizationA->id);

    expect(Stakeholder::all()->pluck('id')->all())->toBe([$stakeholderA->id]);
});

test('tenant models are assigned to the current organization on create', function () {
    $organization = Organization::factory()->create();

    Context::add('organization_id', $organization->id);

    $stakeholder = Stakeholder::create([
        'name' => 'Alex Peeters',
        'email' => 'alex@customer.test',
    ]);

    expect($stakeholder->organization_id)->toBe($organization->id);
});

test('queries run unscoped when no organization context is set', function () {
    Stakeholder::factory()->count(2)->create();

    expect(Stakeholder::count())->toBe(2);
});

test('withoutGlobalScope escapes tenancy for explicit cross-organization work', function () {
    $organizationA = Organization::factory()->create();
    Stakeholder::factory()->for($organizationA)->create();
    Stakeholder::factory()->create();

    Context::add('organization_id', $organizationA->id);

    expect(Stakeholder::count())->toBe(1)
        ->and(Stakeholder::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2);
});

test('the current organization is resolved from the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertSuccessful();

    expect(Context::get('organization_id'))->toBe($user->organization_id);
});

test('guests resolve no organization context', function () {
    $this->get(route('home'))->assertSuccessful();

    expect(Context::get('organization_id'))->toBeNull();
});
