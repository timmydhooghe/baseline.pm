<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
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
