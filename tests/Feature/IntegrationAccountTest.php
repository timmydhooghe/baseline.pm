<?php

use App\Enums\IntegrationProvider;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\IntegrationAccount;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, string>
 */
function jiraAccountPayload(): array
{
    return [
        'provider' => 'jira',
        'name' => 'Studio Jira',
        'base_url' => 'https://example.atlassian.net',
        'email' => 'pm@example.com',
        'api_token' => 'super-secret-token',
    ];
}

test('the owner adds a jira account with encrypted credentials', function () {
    $user = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($user)
        ->post(route('organization.integrations.store'), jiraAccountPayload())
        ->assertRedirect(route('organization.integrations.index'));

    $account = IntegrationAccount::query()->sole();

    expect($account->provider)->toBe(IntegrationProvider::Jira)
        ->and($account->name)->toBe('Studio Jira')
        ->and($account->base_url)->toBe('https://example.atlassian.net')
        ->and($account->credentials)->toBe(['email' => 'pm@example.com', 'api_token' => 'super-secret-token'])
        ->and($account->created_by)->toBe($user->id);

    $raw = (string) DB::table('integration_accounts')->value('credentials');

    expect($raw)->not->toBe('')
        ->and(str_contains($raw, 'super-secret-token'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'integration_account.created')->exists())->toBeTrue();
});

test('the owner adds a linear account with an api key only', function () {
    $user = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($user)
        ->post(route('organization.integrations.store'), [
            'provider' => 'linear',
            'name' => 'Studio Linear',
            'api_token' => 'lin_api_secret',
        ])
        ->assertRedirect(route('organization.integrations.index'));

    $account = IntegrationAccount::query()->sole();

    expect($account->provider)->toBe(IntegrationProvider::Linear)
        ->and($account->credentials)->toBe(['api_token' => 'lin_api_secret'])
        ->and($account->base_url)->toBeNull();
});

test('jira urls outside atlassian cloud are rejected — the server would request them itself', function (string $url) {
    $user = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($user)
        ->post(route('organization.integrations.store'), [...jiraAccountPayload(), 'base_url' => $url])
        ->assertInvalid(['base_url']);

    expect(IntegrationAccount::query()->count())->toBe(0);
})->with([
    'loopback' => ['https://127.0.0.1'],
    'cloud metadata service' => ['https://169.254.169.254/latest/meta-data'],
    'internal hostname' => ['https://jira.internal.corp'],
    'plain http' => ['http://example.atlassian.net'],
    'custom port' => ['https://example.atlassian.net:8443'],
    'lookalike domain' => ['https://evilatlassian.net'],
]);

test('jira accounts require a site url and account email', function () {
    $user = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($user)
        ->post(route('organization.integrations.store'), [
            'provider' => 'jira',
            'name' => 'Studio Jira',
            'api_token' => 'token',
        ])
        ->assertInvalid(['base_url', 'email']);
});

test('account names are unique within the organization', function () {
    $user = User::factory()->role(UserRole::Owner)->create();
    IntegrationAccount::factory()->for($user->organization)->create(['name' => 'Studio Jira']);

    $this->actingAs($user)
        ->post(route('organization.integrations.store'), jiraAccountPayload())
        ->assertInvalid(['name']);
});

test('managers see the accounts page but only the owner manages accounts', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $account = IntegrationAccount::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->get(route('organization.integrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/integrations')
            ->where('accounts.0.id', $account->id)
            ->where('can.manage', false)
            ->missing('accounts.0.credentials')
        );

    $this->actingAs($manager)
        ->post(route('organization.integrations.store'), jiraAccountPayload())
        ->assertForbidden();

    $this->actingAs($manager)
        ->patch(route('organization.integrations.update', $account), ['name' => 'Renamed', 'base_url' => 'https://example.atlassian.net'])
        ->assertForbidden();

    $this->actingAs($manager)
        ->delete(route('organization.integrations.destroy', $account))
        ->assertForbidden();
});

test('members cannot see the accounts page', function () {
    $member = User::factory()->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('organization.integrations.index'))
        ->assertForbidden();
});

test('credentials never reach the accounts page', function () {
    $user = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($user)->post(route('organization.integrations.store'), jiraAccountPayload());

    $this->actingAs($user)
        ->get(route('organization.integrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/integrations')
            ->missing('accounts.0.credentials')
        )
        ->assertDontSee('super-secret-token');
});

test('the owner rotates credentials and the audit entry never carries the secret', function () {
    $user = User::factory()->role(UserRole::Owner)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->patch(route('organization.integrations.update', $account), [
            'name' => 'Renamed Jira',
            'base_url' => 'https://other.atlassian.net',
            'email' => 'new-pm@example.com',
            'api_token' => 'rotated-secret-token',
        ])
        ->assertRedirect(route('organization.integrations.index'));

    $account->refresh();

    expect($account->name)->toBe('Renamed Jira')
        ->and($account->base_url)->toBe('https://other.atlassian.net')
        ->and($account->credentials)->toBe(['email' => 'new-pm@example.com', 'api_token' => 'rotated-secret-token']);

    $audit = AuditLog::query()->where('action', 'integration_account.updated')->sole();

    expect($audit->payload['credentials_rotated'])->toBeTrue()
        ->and(str_contains((string) json_encode($audit->payload), 'rotated-secret-token'))->toBeFalse();
});

test('updating without a token keeps the stored credentials', function () {
    $user = User::factory()->role(UserRole::Owner)->create();
    $account = IntegrationAccount::factory()->for($user->organization)->create();

    $this->actingAs($user)
        ->patch(route('organization.integrations.update', $account), [
            'name' => 'Renamed Jira',
            'base_url' => 'https://example.atlassian.net',
        ])
        ->assertRedirect(route('organization.integrations.index'));

    $account->refresh();

    expect($account->name)->toBe('Renamed Jira')
        ->and($account->credentials)->toBe(['email' => 'pm@example.com', 'api_token' => 'test-api-token']);

    $audit = AuditLog::query()->where('action', 'integration_account.updated')->sole();

    expect($audit->payload['credentials_rotated'])->toBeFalse();
});

test('an unused account can be removed, an in-use account cannot', function () {
    $user = User::factory()->role(UserRole::Owner)->create();
    $connection = IntegrationConnection::factory()->for($user->organization)->create();
    $account = $connection->account;

    $this->actingAs($user)
        ->delete(route('organization.integrations.destroy', $account))
        ->assertInvalid(['account']);

    expect($account->fresh())->not->toBeNull();

    $connection->disconnect($user);

    $this->actingAs($user)
        ->delete(route('organization.integrations.destroy', $account))
        ->assertRedirect(route('organization.integrations.index'));

    expect($account->fresh())->toBeNull()
        ->and(AuditLog::query()->where('action', 'integration_account.deleted')->exists())->toBeTrue();
});

test('accounts from another organization are out of reach', function () {
    $user = User::factory()->role(UserRole::Owner)->create();
    $foreignAccount = IntegrationAccount::factory()->create();

    $this->actingAs($user)
        ->patch(route('organization.integrations.update', $foreignAccount), ['name' => 'Hijack', 'base_url' => 'https://example.atlassian.net'])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('organization.integrations.destroy', $foreignAccount))
        ->assertNotFound();
});
