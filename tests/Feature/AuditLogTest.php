<?php

use App\Models\AuditLog;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Support\Facades\Context;

test('creating an audited model appends a created entry with the actor', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $stakeholder = Stakeholder::factory()->for($user->organization)->create();

    $entry = AuditLog::query()
        ->where('subject_type', $stakeholder->getMorphClass())
        ->where('subject_id', $stakeholder->id)
        ->sole();

    expect($entry->action)->toBe('created')
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->organization_id)->toBe($user->organization_id)
        ->and($entry->payload)->toHaveKey('name', $stakeholder->name);
});

test('updating an audited model records the changes and previous values', function () {
    $stakeholder = Stakeholder::factory()->create(['name' => 'Before']);

    $stakeholder->update(['name' => 'After']);

    $entry = AuditLog::query()
        ->where('subject_id', $stakeholder->id)
        ->where('action', 'updated')
        ->sole();

    expect($entry->payload['changes'])->toBe(['name' => 'After'])
        ->and($entry->payload['previous'])->toBe(['name' => 'Before']);
});

test('deleting an audited model records a deleted entry', function () {
    $stakeholder = Stakeholder::factory()->create();

    $stakeholder->delete();

    expect(
        AuditLog::query()
            ->where('subject_id', $stakeholder->id)
            ->where('action', 'deleted')
            ->exists(),
    )->toBeTrue();
});

test('custom audit actions can be appended directly', function () {
    $stakeholder = Stakeholder::factory()->create();

    AuditLog::record('portal.invited', $stakeholder, ['channel' => 'email']);

    $entry = AuditLog::query()->where('action', 'portal.invited')->sole();

    expect($entry->payload)->toBe(['channel' => 'email'])
        ->and($entry->organization_id)->toBe($stakeholder->organization_id);
});

test('audit log entries cannot be updated', function () {
    $stakeholder = Stakeholder::factory()->create();
    $entry = AuditLog::query()->where('subject_id', $stakeholder->id)->sole();

    $entry->update(['action' => 'tampered']);
})->throws(LogicException::class, 'append-only');

test('audit log entries cannot be deleted', function () {
    $stakeholder = Stakeholder::factory()->create();
    $entry = AuditLog::query()->where('subject_id', $stakeholder->id)->sole();

    $entry->delete();
})->throws(LogicException::class, 'append-only');

test('audit log entries are scoped to the current organization', function () {
    $stakeholderA = Stakeholder::factory()->create();
    Stakeholder::factory()->create();

    Context::add('organization_id', $stakeholderA->organization_id);

    expect(AuditLog::query()->pluck('organization_id')->unique()->all())
        ->toBe([$stakeholderA->organization_id]);
});
