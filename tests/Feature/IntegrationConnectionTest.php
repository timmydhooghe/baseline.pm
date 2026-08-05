<?php

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Enums\UserRole;
use App\Jobs\SyncIntegrationConnection;
use App\Models\AuditLog;
use App\Models\Engagement;
use App\Models\IntegrationConnection;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, string>
 */
function jiraConnectPayload(): array
{
    return [
        'provider' => 'jira',
        'external_project_key' => 'ENG',
        'base_url' => 'https://example.atlassian.net',
        'email' => 'pm@example.com',
        'api_token' => 'super-secret-token',
    ];
}

test('a delivery manager connects jira and the first sync is queued', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), jiraConnectPayload())
        ->assertRedirect(route('engagements.work.show', $engagement));

    $connection = IntegrationConnection::query()->sole();

    expect($connection->provider)->toBe(IntegrationProvider::Jira)
        ->and($connection->status)->toBe(IntegrationConnectionStatus::Connected)
        ->and($connection->external_project_key)->toBe('ENG')
        ->and($connection->credentials)->toBe(['email' => 'pm@example.com', 'api_token' => 'super-secret-token'])
        ->and($connection->connected_by)->toBe($user->id);

    Queue::assertPushed(SyncIntegrationConnection::class, fn (SyncIntegrationConnection $job): bool => $job->integration->is($connection));

    expect(AuditLog::query()->where('action', 'integration.connected')->exists())->toBeTrue();
});

test('a delivery manager connects linear with an api key only', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), [
            'provider' => 'linear',
            'external_project_key' => 'ENG',
            'api_token' => 'lin_api_secret',
        ])
        ->assertRedirect(route('engagements.work.show', $engagement));

    $connection = IntegrationConnection::query()->sole();

    expect($connection->provider)->toBe(IntegrationProvider::Linear)
        ->and($connection->credentials)->toBe(['api_token' => 'lin_api_secret'])
        ->and($connection->base_url)->toBeNull();
});

test('jira requires a site url and account email', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), [
            'provider' => 'jira',
            'external_project_key' => 'ENG',
            'api_token' => 'token',
        ])
        ->assertInvalid(['base_url', 'email']);
});

test('credentials are encrypted at rest and never reach the page props', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)->post(route('engagements.integrations.store', $engagement), jiraConnectPayload());

    $raw = (string) DB::table('integration_connections')->value('credentials');

    expect($raw)->not->toBe('')
        ->and(str_contains($raw, 'super-secret-token'))->toBeFalse();

    $this->actingAs($user)
        ->get(route('engagements.work.show', $engagement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagements/work')
            ->where('connections.0.provider', 'jira')
            ->missing('connections.0.credentials')
        )
        ->assertDontSee('super-secret-token');
});

test('members cannot connect an execution tool', function () {
    $user = User::factory()->role(UserRole::Member)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), jiraConnectPayload())
        ->assertForbidden();
});

test('a provider cannot be connected twice on the same engagement', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();

    $this->actingAs($user)->post(route('engagements.integrations.store', $engagement), jiraConnectPayload());

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), jiraConnectPayload())
        ->assertInvalid(['provider']);

    expect(IntegrationConnection::query()->count())->toBe(1);
});

test('disconnecting wipes credentials but retains the imported history', function () {
    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->create();
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
        ->and($connection->credentials)->toBeNull()
        ->and($connection->disconnected_by)->toBe($user->id)
        ->and($workItem->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'integration.disconnected')->exists())->toBeTrue();
});

test('reconnecting stores fresh credentials and queues a resync into the same record', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($user->organization)->status(EngagementStatus::Active)->create();
    $connection = IntegrationConnection::factory()->disconnected()
        ->for($user->organization)
        ->for($engagement)
        ->create();

    $this->actingAs($user)
        ->post(route('engagements.integrations.store', $engagement), jiraConnectPayload())
        ->assertRedirect(route('engagements.work.show', $engagement));

    $connection->refresh();

    expect(IntegrationConnection::query()->count())->toBe(1)
        ->and($connection->status)->toBe(IntegrationConnectionStatus::Connected)
        ->and($connection->credentials)->toBe(['email' => 'pm@example.com', 'api_token' => 'super-secret-token'])
        ->and($connection->disconnected_at)->toBeNull();

    Queue::assertPushed(SyncIntegrationConnection::class);

    expect(AuditLog::query()->where('action', 'integration.reconnected')->exists())->toBeTrue();
});

test('sync now queues a pass for a connected integration only', function () {
    Queue::fake();

    $user = User::factory()->role(UserRole::DeliveryManager)->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->post(route('integrations.sync', $connection))
        ->assertRedirect(route('engagements.work.show', $connection->engagement));

    Queue::assertPushed(SyncIntegrationConnection::class);

    $disconnected = IntegrationConnection::factory()->disconnected()->linear()->for($user->organization)->create();

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
