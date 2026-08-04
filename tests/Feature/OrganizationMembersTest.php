<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner changes a member role', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $member = User::factory()->for($owner->organization)->create();

    $this->actingAs($owner)
        ->patch(route('organization.members.update', $member), ['role' => 'delivery_manager'])
        ->assertRedirect(route('organization.show'));

    expect($member->refresh()->role)->toBe(UserRole::DeliveryManager)
        ->and(AuditLog::query()
            ->where('action', 'member.role_changed')
            ->where('subject_id', $member->id)
            ->exists())->toBeTrue();
});

test('nobody can be promoted to owner', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $member = User::factory()->for($owner->organization)->create();

    $this->actingAs($owner)
        ->patch(route('organization.members.update', $member), ['role' => 'owner'])
        ->assertSessionHasErrors('role');

    expect($member->refresh()->role)->toBe(UserRole::Member);
});

test('the owner cannot change their own role', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($owner)
        ->patch(route('organization.members.update', $owner), ['role' => 'member'])
        ->assertForbidden();
});

test('non-owners cannot change roles', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $member = User::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->patch(route('organization.members.update', $member), ['role' => 'commercial_manager'])
        ->assertForbidden();
});

test('the owner removes a member', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $member = User::factory()->for($owner->organization)->create();

    $this->actingAs($owner)
        ->delete(route('organization.members.destroy', $member))
        ->assertRedirect(route('organization.show'));

    $this->assertModelMissing($member);

    expect(AuditLog::query()
        ->where('action', 'member.removed')
        ->where('subject_id', $member->id)
        ->exists())->toBeTrue();
});

test('the owner cannot remove themselves', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($owner)
        ->delete(route('organization.members.destroy', $owner))
        ->assertForbidden();

    $this->assertModelExists($owner);
});

test('an owner cannot manage members of another organization', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->patch(route('organization.members.update', $outsider), ['role' => 'member'])
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('organization.members.destroy', $outsider))
        ->assertForbidden();

    $this->assertModelExists($outsider);
});

test('the organization page shows plan usage and member controls to the owner', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($owner)
        ->get(route('organization.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/show')
            ->where('organization.planLabel', 'Solo')
            ->where('planUsage.limit', 1)
            ->where('can.manageMembers', true)
            ->has('assignableRoles', 4));
});

test('members see the organization page without management controls', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('organization.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/show')
            ->where('can.manageMembers', false)
            ->has('invitations', 0));
});
