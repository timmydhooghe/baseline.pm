<?php

use App\Enums\ChangeRequestStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\IntegrationAccount;
use App\Models\IntegrationConnection;
use App\Models\Invitation;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Gate;

$everyRole = [
    'owner' => [UserRole::Owner, true],
    'delivery manager' => [UserRole::DeliveryManager, true],
    'commercial manager' => [UserRole::CommercialManager, true],
    'member' => [UserRole::Member, false],
    'portfolio viewer' => [UserRole::PortfolioViewer, false],
];

test('only the owner may update the organization', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows('update', $user->organization))->toBe($allowed);
})->with([
    'owner' => [UserRole::Owner, true],
    'delivery manager' => [UserRole::DeliveryManager, false],
    'commercial manager' => [UserRole::CommercialManager, false],
    'member' => [UserRole::Member, false],
    'portfolio viewer' => [UserRole::PortfolioViewer, false],
]);

test('members cannot view another organization', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    expect(Gate::forUser($user)->allows('view', $user->organization))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $otherUser->organization))->toBeFalse();
});

test('the audit trail is visible to managing roles only', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows('viewAny', AuditLog::class))->toBe($allowed);
})->with($everyRole);

test('snapshots are captured by managing roles only', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows('create', Snapshot::class))->toBe($allowed);
})->with($everyRole);

test('stakeholders are managed by managing roles only', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows('viewAny', Stakeholder::class))->toBe($allowed);
})->with($everyRole);

test('customers are managed by managing roles only', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows('viewAny', Customer::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('create', Customer::class))->toBe($allowed);
})->with($everyRole);

test('every role views the portfolio, only managing roles change it', function (UserRole $role, bool $managing) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();

    expect(Gate::forUser($user)->allows('viewAny', Engagement::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $engagement))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Engagement::class))->toBe($managing)
        ->and(Gate::forUser($user)->allows('transition', $engagement))->toBe($managing)
        ->and(Gate::forUser($user)->allows('delete', $engagement))->toBeFalse();
})->with($everyRole);

test('only the owner manages invitations', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();
    $invitation = Invitation::factory()->for($user->organization)->create();

    expect(Gate::forUser($user)->allows('create', Invitation::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('delete', $invitation))->toBe($allowed);
})->with([
    'owner' => [UserRole::Owner, true],
    'delivery manager' => [UserRole::DeliveryManager, false],
    'commercial manager' => [UserRole::CommercialManager, false],
    'member' => [UserRole::Member, false],
    'portfolio viewer' => [UserRole::PortfolioViewer, false],
]);

test('every executing role maps work, portfolio viewers only look at it', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();
    $workItem = WorkItem::factory()->for($user->organization)->for($engagement)->create();

    expect(Gate::forUser($user)->allows('view', $workItem))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', [WorkItem::class, $engagement]))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('linkAny', [WorkItem::class, $engagement]))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('link', $workItem))->toBe($allowed);
})->with([
    'owner' => [UserRole::Owner, true],
    'delivery manager' => [UserRole::DeliveryManager, true],
    'commercial manager' => [UserRole::CommercialManager, true],
    'member' => [UserRole::Member, true],
    'portfolio viewer' => [UserRole::PortfolioViewer, false],
]);

test('change control is governed by managing roles only', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();
    $changeRequest = ChangeRequest::factory()->for($user->organization)->for($engagement)->create();

    expect(Gate::forUser($user)->allows('view', $changeRequest))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', [ChangeRequest::class, $engagement]))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('update', $changeRequest))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('delete', $changeRequest))->toBeFalse();
})->with($everyRole);

test('a decided change request cannot be updated even by managers', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $changeRequest = ChangeRequest::factory()
        ->for($manager->organization)
        ->status(ChangeRequestStatus::Approved)
        ->create();

    expect(Gate::forUser($manager)->allows('update', $changeRequest))->toBeFalse();
});

test('drift triage is a governance call for managing roles only', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();
    $workItem = WorkItem::factory()->for($user->organization)->for($engagement)->create();

    expect(Gate::forUser($user)->allows('triageAny', [WorkItem::class, $engagement]))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('triage', $workItem))->toBe($allowed);
})->with($everyRole);

test('integrations are wired by managing roles and never deleted', function (UserRole $role, bool $allowed) {
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->for($engagement)->create();

    expect(Gate::forUser($user)->allows('create', [IntegrationConnection::class, $engagement]))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('disconnect', $connection))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('sync', $connection))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('delete', $connection))->toBeFalse();
})->with($everyRole);

test('integration accounts are visible to managing roles and managed by the owner only', function (UserRole $role, bool $sees) {
    $user = User::factory()->role($role)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $ownerOnly = $role === UserRole::Owner;

    expect(Gate::forUser($user)->allows('viewAny', IntegrationAccount::class))->toBe($sees)
        ->and(Gate::forUser($user)->allows('view', $account))->toBe($sees)
        ->and(Gate::forUser($user)->allows('create', IntegrationAccount::class))->toBe($ownerOnly)
        ->and(Gate::forUser($user)->allows('update', $account))->toBe($ownerOnly)
        ->and(Gate::forUser($user)->allows('delete', $account))->toBe($ownerOnly);
})->with($everyRole);

test('audit logs and snapshots can never be mutated, even by the owner', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $stakeholder = Stakeholder::factory()->for($owner->organization)->create();
    $entry = AuditLog::query()->where('subject_id', $stakeholder->id)->sole();
    $snapshot = Snapshot::capture($stakeholder, [], $owner);

    expect(Gate::forUser($owner)->allows('update', $entry))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $entry))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $snapshot))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $snapshot))->toBeFalse();
});

test('only the owner manages members, and never deletes themselves', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();
    $member = User::factory()->for($owner->organization)->create();
    $outsider = User::factory()->create();

    expect(Gate::forUser($owner)->allows('delete', $member))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $owner))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $outsider))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $owner))->toBeFalse();
});
