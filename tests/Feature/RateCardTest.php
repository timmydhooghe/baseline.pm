<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RateCardRole;
use App\Models\RateCardVersion;
use App\Models\User;
use App\ValueObjects\Money;
use Inertia\Testing\AssertableInertia as Assert;

function rateCardPayload(): array
{
    return [
        'roles' => [
            ['name' => 'Senior developer', 'cost_per_day' => '450', 'sell_per_day' => '780.50'],
            ['name' => 'Designer', 'cost_per_day' => '380', 'sell_per_day' => '650'],
        ],
    ];
}

test('a commercial manager publishes the first rate card version', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)
        ->post(route('organization.rate-card.store'), rateCardPayload())
        ->assertRedirect(route('organization.rate-card.show'));

    $version = RateCardVersion::query()->sole();

    expect($version->version)->toBe(1)
        ->and($version->organization_id)->toBe($manager->organization_id)
        ->and($version->created_by)->toBe($manager->id);

    $developer = $version->roles()->where('name', 'Senior developer')->sole();

    expect($developer->cost_per_day->amount)->toBe(45000)
        ->and($developer->sell_per_day->amount)->toBe(78050)
        ->and($developer->sell_per_day->currency)->toBe('EUR');
});

test('the owner can publish a rate card version', function () {
    $owner = User::factory()->role(UserRole::Owner)->create();

    $this->actingAs($owner)
        ->post(route('organization.rate-card.store'), rateCardPayload())
        ->assertRedirect(route('organization.rate-card.show'));

    expect(RateCardVersion::query()->count())->toBe(1);
});

test('publishing again creates the next version and leaves history untouched', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)->post(route('organization.rate-card.store'), rateCardPayload());
    $this->actingAs($manager)->post(route('organization.rate-card.store'), [
        'roles' => [
            ['name' => 'Senior developer', 'cost_per_day' => '475', 'sell_per_day' => '800'],
        ],
    ]);

    expect(RateCardVersion::query()->pluck('version')->all())->toBe([1, 2]);

    $original = RateCardVersion::query()->where('version', 1)->sole()
        ->roles()->where('name', 'Senior developer')->sole();

    expect($original->cost_per_day->amount)->toBe(45000);
});

test('version numbers are sequential per organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $roles = [['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)]];

    $organizationA->publishRateCardVersion($roles);
    $organizationA->publishRateCardVersion($roles);
    $firstForB = $organizationB->publishRateCardVersion($roles);

    expect($firstForB->version)->toBe(1)
        ->and($organizationA->currentRateCardVersion()?->version)->toBe(2);
});

test('the rate card page lists versions newest first', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $manager->organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
    ]);
    $manager->organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(47500), 'sell_per_day' => Money::fromCents(80000)],
    ]);

    $this->actingAs($manager)
        ->get(route('organization.rate-card.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/rate-card')
            ->has('versions', 2)
            ->where('versions.0.version', 2)
            ->where('versions.0.roles.0.costPerDay.amount', 47500)
            ->where('can.manage', false));
});

test('a delivery manager views the rate card but cannot publish', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();

    $this->actingAs($manager)->get(route('organization.rate-card.show'))->assertSuccessful();
    $this->actingAs($manager)->post(route('organization.rate-card.store'), rateCardPayload())->assertForbidden();
});

test('members and portfolio viewers cannot access the rate card', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)->get(route('organization.rate-card.show'))->assertForbidden();
    $this->actingAs($user)->post(route('organization.rate-card.store'), rateCardPayload())->assertForbidden();
})->with([
    'member' => UserRole::Member,
    'portfolio viewer' => UserRole::PortfolioViewer,
]);

test('the rate card of another organization is not visible', function () {
    Organization::factory()->create()->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
    ]);

    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)
        ->get(route('organization.rate-card.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('versions', 0));
});

test('role names must be unique within a version', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)
        ->post(route('organization.rate-card.store'), [
            'roles' => [
                ['name' => 'Developer', 'cost_per_day' => '450', 'sell_per_day' => '780'],
                ['name' => 'developer', 'cost_per_day' => '400', 'sell_per_day' => '700'],
            ],
        ])
        ->assertInvalid(['roles.0.name', 'roles.1.name']);
});

test('rates must be non-negative amounts with at most two decimals', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)
        ->post(route('organization.rate-card.store'), [
            'roles' => [
                ['name' => 'Developer', 'cost_per_day' => '-10', 'sell_per_day' => '780.505'],
            ],
        ])
        ->assertInvalid(['roles.0.cost_per_day', 'roles.0.sell_per_day']);
});

test('a rate card without roles is refused', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)
        ->post(route('organization.rate-card.store'), ['roles' => []])
        ->assertInvalid(['roles']);
});

test('a rate card version cannot be updated', function () {
    $version = RateCardVersion::factory()->create();

    $version->update(['version' => 9]);
})->throws(LogicException::class, 'Rate card versions are immutable');

test('a rate card version cannot be deleted', function () {
    $version = RateCardVersion::factory()->create();

    $version->delete();
})->throws(LogicException::class, 'Rate card versions are immutable');

test('a published rate cannot be changed or removed', function () {
    $role = RateCardRole::factory()->create();

    expect(fn () => $role->update(['cost_per_day' => Money::fromCents(1)]))
        ->toThrow(LogicException::class, 'Rate card roles are immutable');

    expect(fn () => $role->delete())
        ->toThrow(LogicException::class, 'Rate card roles are immutable');
});

test('publishing a rate card version is recorded in the audit log', function () {
    $manager = User::factory()->role(UserRole::CommercialManager)->create();

    $this->actingAs($manager)->post(route('organization.rate-card.store'), rateCardPayload());

    $version = RateCardVersion::query()->sole();

    $entry = AuditLog::query()
        ->where('subject_type', $version->getMorphClass())
        ->where('subject_id', $version->id)
        ->where('action', 'created')
        ->sole();

    expect($entry->actor_id)->toBe($manager->id)
        ->and(AuditLog::query()->where('subject_type', (new RateCardRole)->getMorphClass())->count())->toBe(2);
});
