<?php

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Enums\UserRole;
use App\Jobs\SyncIntegrationConnection;
use App\Models\AuditLog;
use App\Models\Engagement;
use App\Models\IntegrationAccount;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, string>
 */
function connectPayload(IntegrationAccount $account): array
{
    return [
        'integration_account_id' => $account->id,
        'external_project_key' => 'ENG',
    ];
}

test('a delivery manager connects jira through an org account and the first sync is queued', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($account))
        ->assertRedirect(route('engagements.work.show', $engagement));

    $connection = IntegrationConnection::query()->sole();

    expect($connection->provider)->toBe(IntegrationProvider::Jira)
        ->and($connection->status)->toBe(IntegrationConnectionStatus::Connected)
        ->and($connection->external_project_key)->toBe('ENG')
        ->and($connection->integration_account_id)->toBe($account->id)
        ->and($connection->connected_by)->toBe($user->id);

    Queue::assertPushed(SyncIntegrationConnection::class, fn (SyncIntegrationConnection $job): bool => $job->integration->is($connection));

    expect(AuditLog::query()->where('action', 'integration.connected')->exists())->toBeTrue();
});

test('a delivery manager connects linear through a linear account', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->linear()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($account))
        ->assertRedirect(route('engagements.work.show', $engagement));

    $connection = IntegrationConnection::query()->sole();

    expect($connection->provider)->toBe(IntegrationProvider::Linear)
        ->and($connection->account?->is($account))->toBeTrue();
});

test('an account from another organization reads as nonexistent', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $foreignAccount = IntegrationAccount::factory()->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($foreignAccount))
        ->assertInvalid(['integration_account_id']);

    expect(IntegrationConnection::query()->count())->toBe(0);
});

test('members cannot connect an execution tool', function () {
    $user = User::factory()->role(UserRole::Member)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($account))
        ->assertForbidden();
});

test('a provider cannot be connected twice on the same engagement', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $secondJiraAccount = IntegrationAccount::factory()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)->post(route('engagements.integrations.store', $engagement), connectPayload($account));

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($secondJiraAccount))
        ->assertInvalid(['integration_account_id']);

    expect(IntegrationConnection::query()->count())->toBe(1);
});

test('connections carry no credentials anywhere in the work page props', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)->post(route('engagements.integrations.store', $engagement), connectPayload($account));

    $this->actingAs($user)
        ->get(route('engagements.work.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/work')
            ->where('connections.0.provider', 'jira')
            ->where('connections.0.accountName', $account->name)
            ->missing('connections.0.credentials')
            ->missing('accounts.0.credentials')
        )
        ->assertDontSee('test-api-token');
});

test('disconnecting drops the account link but retains the imported history and the account', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->create();
    $account = $connection->account;
    $workItem = WorkItem::factory()->jira()
        ->for($user->organization)
        ->for($connection->engagement)
        ->for($connection, 'integration')
        ->create();

    $this->actingAs($user)
        ->post(route('integrations.disconnect', $connection))
        ->assertRedirect(route('engagements.work.show', $connection->engagement));

    $connection->refresh();

    expect($connection->status)->toBe(IntegrationConnectionStatus::Disconnected)
        ->and($connection->integration_account_id)->toBeNull()
        ->and($connection->disconnected_by)->toBe($user->id)
        ->and($workItem->fresh())->not->toBeNull()
        ->and($account->fresh()->credentials)->toBe(['email' => 'pm@example.com', 'api_token' => 'test-api-token'])
        ->and(AuditLog::query()->where('action', 'integration.disconnected')->exists())->toBeTrue();
});

test('reconnecting through an account queues a resync into the same record', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();
    $connection = IntegrationConnection::factory()->disconnected()
        ->for($user->organization)
        ->for($engagement)
        ->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($account))
        ->assertRedirect(route('engagements.work.show', $engagement));

    $connection->refresh();

    expect(IntegrationConnection::query()->count())->toBe(1)
        ->and($connection->status)->toBe(IntegrationConnectionStatus::Connected)
        ->and($connection->integration_account_id)->toBe($account->id)
        ->and($connection->disconnected_at)->toBeNull();

    Queue::assertPushed(SyncIntegrationConnection::class);

    expect(AuditLog::query()->where('action', 'integration.reconnected')->exists())->toBeTrue();
});

test('reconnecting may switch to a different account of the same provider', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();
    $connection = IntegrationConnection::factory()->disconnected()
        ->for($user->organization)
        ->for($engagement)
        ->create();
    $otherJiraAccount = IntegrationAccount::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), connectPayload($otherJiraAccount))
        ->assertRedirect(route('engagements.work.show', $engagement));

    expect($connection->fresh()->integration_account_id)->toBe($otherJiraAccount->id);
});

test('sync now queues a pass for a connected integration only', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('integrations.sync', $connection))
        ->assertRedirect(route('engagements.work.show', $connection->engagement));

    Queue::assertPushed(SyncIntegrationConnection::class);

    $disconnected = IntegrationConnection::factory()->linear()->disconnected()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('integrations.sync', $disconnected))
        ->assertForbidden();
});

test('the work page shows the connection with its sync status and latest runs', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $connection = IntegrationConnection::factory()
        ->for($user->organization)
        ->create(['last_synced_at' => now()->subMinutes(10)]);
    SyncRun::factory()
        ->for($user->organization)
        ->for($connection, 'integration')
        ->create(['counts' => ['work_items' => 2, 'worklogs' => 1, 'releases' => 1]]);

    $this->actingAs($user)
        ->get(route('engagements.work.show', $connection->engagement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/work')
            ->where('connections.0.statusLabel', 'Connected')
            ->where('connections.0.runs.0.status', 'succeeded')
            ->where('connections.0.runs.0.counts.work_items', 2)
            ->etc()
        );
});

test('the work page offers the org accounts to managers for the connect dropdown', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->get(route('engagements.work.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/work')
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.name', $account->name)
            ->where('can.manageAccounts', false)
            ->etc()
        );
});
