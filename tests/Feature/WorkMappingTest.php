<?php

use App\Enums\BaselineItemType;
use App\Enums\EngagementStatus;
use App\Enums\UserRole;
use App\Jobs\PushWorkItemLink;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemLink;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/**
 * An engagement with a draft baseline holding one deliverable, plus a
 * connected Jira integration.
 *
 * @return array{user: User, engagement: Engagement, deliverable: BaselineItem, connection: IntegrationConnection}
 */
function mappingSetup(UserRole $role = UserRole::Member): array
{
    $user = User::factory()->role($role)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();
    $baseline = Baseline::factory()->for($user->organization)->for($engagement)->create();
    $deliverable = BaselineItem::factory()
        ->for($user->organization)
        ->for($baseline)
        ->type(BaselineItemType::Deliverable)
        ->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->for($engagement)->create();

    return ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable, 'connection' => $connection];
}

test('a member maps imported work items to a deliverable in bulk, recorded with who and when', function () {
    Queue::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable, 'connection' => $connection] = mappingSetup();

    $items = WorkItem::factory()->jira()
        ->count(2)
        ->for($user->organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create();

    $this->actingAs($user)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => $items->pluck('id')->all(),
            'baseline_item_id' => $deliverable->id,
        ])
        ->assertRedirect(route('engagements.work.show', $engagement));

    foreach ($items as $item) {
        $link = $item->refresh()->link;

        expect($link)->not->toBeNull()
            ->and($link->baseline_item_id)->toBe($deliverable->id)
            ->and($link->linked_by)->toBe($user->id)
            ->and($link->created_at)->not->toBeNull();
    }

    expect(AuditLog::query()->where('action', 'work_item.linked')->count())->toBe(2);

    Queue::assertPushed(PushWorkItemLink::class, 2);
});

test('mapping a manual work item pushes nothing back to a provider', function () {
    Queue::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $manual = WorkItem::factory()->for($user->organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => [$manual->id],
            'baseline_item_id' => $deliverable->id,
        ])
        ->assertRedirect();

    expect($manual->refresh()->link)->not->toBeNull();

    Queue::assertNotPushed(PushWorkItemLink::class);
});

test('relinking replaces the mapping instead of stacking a second one', function () {
    Queue::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $other = BaselineItem::factory()
        ->for($user->organization)
        ->for($deliverable->baseline)
        ->type(BaselineItemType::Deliverable)
        ->create(['position' => 2]);

    $item = WorkItem::factory()->for($user->organization)->for($engagement)->create();
    $item->linkTo($deliverable, $user);

    $this->actingAs($user)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => [$item->id],
            'baseline_item_id' => $other->id,
        ])
        ->assertRedirect();

    expect($item->refresh()->link?->baseline_item_id)->toBe($other->id)
        ->and(WorkItemLink::query()->where('work_item_id', $item->id)->count())->toBe(1);
});

test('work can only be mapped to a deliverable', function () {
    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $milestone = BaselineItem::factory()
        ->for($user->organization)
        ->for($deliverable->baseline)
        ->completeMilestone()
        ->create(['position' => 2]);

    $item = WorkItem::factory()->for($user->organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => [$item->id],
            'baseline_item_id' => $milestone->id,
        ])
        ->assertInvalid(['baseline_item_id']);

    expect($item->refresh()->link)->toBeNull();
});

test('work cannot be mapped to a deliverable of another engagement', function () {
    ['user' => $user, 'engagement' => $engagement] = mappingSetup();

    $otherBaseline = Baseline::factory()->for($user->organization)->create();
    $foreignDeliverable = BaselineItem::factory()
        ->for($user->organization)
        ->for($otherBaseline)
        ->type(BaselineItemType::Deliverable)
        ->create();

    $item = WorkItem::factory()->for($user->organization)->for($engagement)->create();

    $this->actingAs($user)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => [$item->id],
            'baseline_item_id' => $foreignDeliverable->id,
        ])
        ->assertInvalid(['baseline_item_id']);
});

test('work items of another engagement cannot ride along in a bulk mapping', function () {
    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $foreignItem = WorkItem::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => [$foreignItem->id],
            'baseline_item_id' => $deliverable->id,
        ])
        ->assertInvalid(['work_item_ids']);
});

test('portfolio viewers cannot map work', function () {
    ['engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $viewer = User::factory()->role(UserRole::PortfolioViewer)->for($engagement->organization)->create();
    $item = WorkItem::factory()->for($engagement->organization)->for($engagement)->create();

    $this->actingAs($viewer)
        ->post(route('engagements.work-item-links.store', $engagement), [
            'work_item_ids' => [$item->id],
            'baseline_item_id' => $deliverable->id,
        ])
        ->assertForbidden();
});

test('unlinking removes the mapping and stays on the audit record', function () {
    Queue::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $item = WorkItem::factory()->for($user->organization)->for($engagement)->create();
    $item->linkTo($deliverable, $user);

    $this->actingAs($user)
        ->delete(route('work-items.link.destroy', $item))
        ->assertRedirect(route('engagements.work.show', $engagement));

    expect($item->refresh()->link)->toBeNull()
        ->and(AuditLog::query()->where('action', 'work_item.unlinked')->exists())->toBeTrue();
});

test('mappings on an archived engagement cannot be unlinked — the record is frozen', function () {
    Queue::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $item = WorkItem::factory()->for($user->organization)->for($engagement)->create();
    $item->linkTo($deliverable, $user);

    $engagement->forceFill(['status' => EngagementStatus::Archived])->save();

    $this->actingAs($user)
        ->delete(route('work-items.link.destroy', $item))
        ->assertForbidden();

    expect($item->refresh()->link)->not->toBeNull();
});

test('mapping a synced jira item posts a comment back on the issue', function () {
    Http::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable, 'connection' => $connection] = mappingSetup();

    $item = WorkItem::factory()->jira()
        ->for($user->organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create(['external_key' => 'ENG-42']);

    (new PushWorkItemLink($item, $deliverable))->handle();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/api/3/issue/ENG-42/comment')
        && str_contains($request->body(), $deliverable->title));
});

test('mapping a synced linear item posts a comment through the graphql api', function () {
    Http::fake(['api.linear.app/*' => Http::response(['data' => ['commentCreate' => ['success' => true]]])]);

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $connection = IntegrationConnection::factory()->linear()->for($user->organization)->for($engagement)->create();
    $item = WorkItem::factory()->linear()
        ->for($user->organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create();

    (new PushWorkItemLink($item, $deliverable))->handle();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.linear.app')
        && str_contains($request->body(), 'commentCreate'));
});

test('the outbound push skips quietly when the connection was disconnected', function () {
    Http::fake();

    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $connection = IntegrationConnection::factory()->disconnected()->linear()->for($user->organization)->for($engagement)->create();
    $item = WorkItem::factory()->linear()
        ->for($user->organization)
        ->for($engagement)
        ->for($connection, 'integration')
        ->create();

    (new PushWorkItemLink($item, $deliverable))->handle();

    Http::assertNothingSent();
});

test('the work page reports how much work is still unmapped', function () {
    ['user' => $user, 'engagement' => $engagement, 'deliverable' => $deliverable] = mappingSetup();

    $linked = WorkItem::factory()->for($user->organization)->for($engagement)->create();
    $linked->linkTo($deliverable, $user);
    WorkItem::factory()->count(2)->for($user->organization)->for($engagement)->create();

    $this->actingAs($user)
        ->get(route('engagements.work.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/work')
            ->where('mapping.total', 3)
            ->where('mapping.linked', 1)
            ->where('mapping.unlinked', 2)
            ->etc()
        );
});
