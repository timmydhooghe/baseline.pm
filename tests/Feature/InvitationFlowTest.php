<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner invites a member by email', function () {
    Notification::fake();

    $owner = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($owner)
        ->post(route('organization.invitations.store'), [
            'email' => 'new@example.com',
            'role' => 'delivery_manager',
        ])
        ->assertRedirect(route('organization.show'));

    $invitation = Invitation::query()->where('email', 'new@example.com')->sole();

    expect($invitation->organization_id)->toBe($owner->organization_id)
        ->and($invitation->role)->toBe(UserRole::DeliveryManager)
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and($invitation->isExpired())->toBeFalse();

    Notification::assertSentOnDemand(
        MemberInvitation::class,
        fn (MemberInvitation $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'new@example.com'
            && $notification->invitation->is($invitation),
    );
});

test('an email with a pending invitation cannot be invited twice', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    Invitation::factory()->for($owner->organization)->create(['email' => 'twice@example.com']);

    $this->actingAs($owner)
        ->post(route('organization.invitations.store'), [
            'email' => 'twice@example.com',
            'role' => 'member',
        ])
        ->assertSessionHasErrors('email');
});

test('an existing member cannot be invited', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $member = User::factory()->for($owner->organization)->create();

    $this->actingAs($owner)
        ->post(route('organization.invitations.store'), [
            'email' => $member->email,
            'role' => 'member',
        ])
        ->assertSessionHasErrors('email');
});

test('an invitation can never grant the owner role', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($owner)
        ->post(route('organization.invitations.store'), [
            'email' => 'second-owner@example.com',
            'role' => 'owner',
        ])
        ->assertSessionHasErrors('role');
});

test('non-owners cannot invite members', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)
        ->post(route('organization.invitations.store'), [
            'email' => 'new@example.com',
            'role' => 'member',
        ])
        ->assertForbidden();
})->with([
    'delivery manager' => UserRole::DeliveryManager,
    'commercial manager' => UserRole::CommercialManager,
    'member' => UserRole::Member,
    'portfolio viewer' => UserRole::PortfolioViewer,
]);

test('the owner revokes a pending invitation', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $invitation = Invitation::factory()->for($owner->organization)->create();

    $this->actingAs($owner)
        ->delete(route('organization.invitations.destroy', $invitation))
        ->assertRedirect(route('organization.show'));

    $this->assertModelMissing($invitation);
});

test('another organization\'s invitation is invisible to an owner', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $foreignInvitation = Invitation::factory()->create();

    $this->actingAs($owner)
        ->delete(route('organization.invitations.destroy', $foreignInvitation))
        ->assertNotFound();

    $this->assertModelExists($foreignInvitation);
});

test('a guest sees the acceptance page for a valid token', function () {
    $invitation = Invitation::factory()->role(UserRole::CommercialManager)->create();

    $this->get(route('invitations.show', ['token' => $invitation->token]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('invitation.email', $invitation->email)
            ->where('invitation.roleLabel', 'Commercial manager')
            ->where('invitation.isExpired', false));
});

test('an unknown invitation token is a 404', function () {
    $this->get(route('invitations.show', ['token' => str_repeat('x', 64)]))
        ->assertNotFound();
});

test('an authenticated user is redirected away from the acceptance page', function () {
    $invitation = Invitation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('invitations.show', ['token' => $invitation->token]))
        ->assertRedirect();
});

test('a guest accepts an invitation and becomes a member', function () {
    $invitation = Invitation::factory()->role(UserRole::PortfolioViewer)->create();

    $this->post(route('invitations.store', ['token' => $invitation->token]), [
        'name' => 'Ada Verhoeven',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', $invitation->email)->sole();

    expect($user->organization_id)->toBe($invitation->organization_id)
        ->and($user->role)->toBe(UserRole::PortfolioViewer)
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
    $this->assertModelMissing($invitation);

    expect(AuditLog::query()
        ->where('action', 'member.joined')
        ->where('subject_id', $user->id)
        ->exists())->toBeTrue();
});

test('an expired invitation cannot be accepted', function () {
    $invitation = Invitation::factory()->expired()->create();

    $this->get(route('invitations.show', ['token' => $invitation->token]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('invitation.isExpired', true));

    $this->post(route('invitations.store', ['token' => $invitation->token]), [
        'name' => 'Too Late',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect(route('invitations.show', ['token' => $invitation->token]));

    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();
    $this->assertGuest();
});

test('an invitation for an email that registered elsewhere cannot be accepted', function () {
    $invitation = Invitation::factory()->create();
    User::factory()->create(['email' => $invitation->email]);

    $this->post(route('invitations.store', ['token' => $invitation->token]), [
        'name' => 'Duplicate',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertSessionHasErrors('name');

    $this->assertGuest();
    $this->assertModelExists($invitation);
});
