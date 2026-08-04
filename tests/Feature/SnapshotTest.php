<?php

use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;

test('capture freezes the payload with its fingerprint', function () {
    $user = User::factory()->create();
    $stakeholder = Stakeholder::factory()->for($user->organization)->create();

    $snapshot = Snapshot::capture($stakeholder, ['scope' => 'v1', 'days' => 20], $user);

    expect($snapshot->payload)->toBe(['scope' => 'v1', 'days' => 20])
        ->and($snapshot->organization_id)->toBe($user->organization_id)
        ->and($snapshot->created_by)->toBe($user->id)
        ->and($snapshot->subject_id)->toBe($stakeholder->id)
        ->and($snapshot->verifyIntegrity())->toBeTrue();
});

test('the hash is independent of key order', function () {
    $ordered = Snapshot::hashPayload(['a' => 1, 'b' => ['x' => 1, 'y' => 2]]);
    $shuffled = Snapshot::hashPayload(['b' => ['y' => 2, 'x' => 1], 'a' => 1]);

    expect($ordered)->toBe($shuffled)->toHaveLength(64);
});

test('the creator defaults to the authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $snapshot = Snapshot::capture(Stakeholder::factory()->for($user->organization)->create(), []);

    expect($snapshot->created_by)->toBe($user->id);
});

test('snapshots cannot be updated', function () {
    $snapshot = Snapshot::capture(Stakeholder::factory()->create(), ['v' => 1]);

    $snapshot->update(['payload' => ['v' => 2]]);
})->throws(LogicException::class, 'immutable');

test('snapshots cannot be deleted', function () {
    $snapshot = Snapshot::capture(Stakeholder::factory()->create(), ['v' => 1]);

    $snapshot->delete();
})->throws(LogicException::class, 'immutable');

test('tampered payloads fail the integrity check', function () {
    $snapshot = Snapshot::capture(Stakeholder::factory()->create(), ['v' => 1]);

    Snapshot::withoutEvents(function () use ($snapshot): void {
        $snapshot->newQuery()
            ->whereKey($snapshot->id)
            ->toBase()
            ->update(['payload' => json_encode(['v' => 'tampered'])]);
    });

    expect($snapshot->fresh()->verifyIntegrity())->toBeFalse();
});
