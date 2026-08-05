<?php

use App\Enums\EngagementStatus;
use App\Enums\EstimateUnit;
use App\Enums\UserRole;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemState;
use App\Models\AuditLog;
use App\Models\Engagement;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Queue;

test('a member records a manual work item in standalone mode', function () {
    $user = User::factory()->role(UserRole::Member)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.work-items.store', $engagement), [
            'title' => 'Set up staging environment',
            'state' => 'in_progress',
            'type' => 'Task',
            'assignee_name' => 'Sam Ops',
            'estimate_days' => 2.5,
        ])
        ->assertRedirect(route('engagements.work.show', $engagement));

    $item = $engagement->workItems()->sole();

    expect($item->source)->toBe(WorkItemSource::Manual)
        ->and($item->state)->toBe(WorkItemState::InProgress)
        ->and($item->estimate_value)->toBe(2.5)
        ->and($item->estimate_unit)->toBe(EstimateUnit::Days)
        ->and($item->created_by)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'work_item.recorded')->exists())->toBeTrue();
});

test('a member updates a manual work item and the change is audited', function () {
    $user = User::factory()->role(UserRole::Member)->create();
    $item = WorkItem::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->patch(route('work-items.update', $item), [
            'title' => 'Set up staging & production environments',
            'state' => 'done',
        ])
        ->assertRedirect(route('engagements.work.show', $item->engagement));

    expect($item->refresh()->title)->toBe('Set up staging & production environments')
        ->and($item->state)->toBe(WorkItemState::Done)
        ->and(AuditLog::query()->where('action', 'work_item.updated')->exists())->toBeTrue();
});

test('synced work items cannot be edited by hand — they mirror the provider', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $item = WorkItem::factory()->jira()->for($user->organization)->create();

    $this->actingAs($user)
        ->patch(route('work-items.update', $item), ['title' => 'Renamed locally'])
        ->assertForbidden();
});

test('a member logs time on a manual work item in hours', function () {
    $user = User::factory()->role(UserRole::Member)->create();
    $item = WorkItem::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('work-items.worklogs.store', $item), [
            'hours' => 3.5,
            'logged_on' => now()->toDateString(),
        ])
        ->assertRedirect(route('engagements.work.show', $item->engagement));

    $worklog = $item->worklogs()->sole();

    expect($worklog->seconds)->toBe(12600)
        ->and($worklog->author_name)->toBe($user->name)
        ->and($worklog->created_by)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'work_item.worklog_recorded')->exists())->toBeTrue();
});

test('time cannot be logged by hand on a synced item — that would double-count the provider worklogs', function () {
    $user = User::factory()->role(UserRole::Member)->create();
    $item = WorkItem::factory()->jira()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('work-items.worklogs.store', $item), [
            'hours' => 2,
            'logged_on' => now()->toDateString(),
        ])
        ->assertForbidden();
});

test('portfolio viewers cannot record work', function () {
    $viewer = User::factory()->role(UserRole::PortfolioViewer)->create();
    $engagement = Engagement::factory()->for($viewer->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($viewer)
        ->post(route('engagements.work-items.store', $engagement), [
            'title' => 'Sneaky item',
            'state' => 'todo',
        ])
        ->assertForbidden();
});

test('work on an archived engagement is read-only', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Archived)->create();
    $item = WorkItem::factory()->for($user->organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('engagements.work-items.store', $engagement), ['title' => 'Late addition', 'state' => 'todo'])
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('work-items.update', $item), ['title' => 'Renamed after archival'])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('work-items.worklogs.store', $item), ['hours' => 2, 'logged_on' => now()->toDateString()])
        ->assertForbidden();
});

test('connecting a tool later keeps the manual history — standalone upgrades without loss', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();
    WorkItem::factory()->count(3)->for($user->organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), [
            'provider' => 'linear',
            'external_project_key' => 'ENG',
            'api_token' => 'lin_api_secret',
        ])
        ->assertRedirect();

    expect($engagement->workItems()->where('source', WorkItemSource::Manual)->count())->toBe(3)
        ->and($engagement->integrationConnections()->count())->toBe(1);
});
