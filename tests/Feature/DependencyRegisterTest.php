<?php

use App\Enums\BaselineStatus;
use App\Enums\DependencyEventType;
use App\Enums\DependencyParty;
use App\Enums\DependencyStatus;
use App\Enums\EngagementStatus;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Customer;
use App\Models\Dependency;
use App\Models\DependencyEvent;
use App\Models\Engagement;
use App\Models\Stakeholder;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * A delivery manager on an active engagement whose approved baseline carries
 * a go-live milestone dated 30 days out, plus a customer with one contact.
 *
 * @return array<string, mixed>
 */
function dependencySetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create(['name' => 'Dana Mertens']);
    $organization = $manager->organization;

    $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
    ]);

    $customer = Customer::factory()->for($organization)->create(['name' => 'Acme Industries']);
    $engagement = Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'contract_value' => Money::fromCents(2000000),
    ]);

    $milestone = BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live',
        'baseline_date' => today()->addDays(30),
    ]);

    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    $contact = Stakeholder::factory()
        ->for($organization)
        ->for($customer)
        ->role(StakeholderRole::ProjectManager)
        ->create(['name' => 'Anders Vik']);

    return [
        'manager' => $manager,
        'organization' => $organization,
        'customer' => $customer,
        'engagement' => $engagement,
        'milestone' => $milestone,
        'contact' => $contact,
    ];
}

test('a customer-owed dependency names a contact and reaches their action list', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact, 'milestone' => $milestone] = dependencySetup();

    $this->actingAs($manager)
        ->post(route('engagements.dependencies.store', $engagement), [
            'title' => 'Production database credentials',
            'description' => 'Read-write access for the migration job.',
            'party' => DependencyParty::Customer->value,
            'responsible_stakeholder_id' => $contact->id,
            'required_on' => today()->addDays(7)->toDateString(),
            'visibility' => 'shared',
            'links' => [['type' => BaselineItem::class, 'id' => $milestone->id]],
        ])
        ->assertRedirect();

    $dependency = Dependency::query()->sole();

    expect($dependency->party)->toBe(DependencyParty::Customer)
        ->and($dependency->responsibleName())->toBe('Anders Vik')
        ->and($dependency->status)->toBe(DependencyStatus::Pending)
        ->and($dependency->links->sole()->affected_id)->toBe($milestone->id)
        ->and($engagement->customerOwedDependencies()->pluck('id')->all())->toBe([$dependency->id])
        ->and(AuditLog::query()->where('action', 'dependency.registered')->exists())->toBeTrue();
});

test('a dependency nobody owns is refused', function () {
    ['manager' => $manager, 'engagement' => $engagement] = dependencySetup();

    $this->actingAs($manager)
        ->post(route('engagements.dependencies.store', $engagement), [
            'title' => 'Something vague',
            'party' => DependencyParty::Customer->value,
            'required_on' => today()->addWeek()->toDateString(),
            'visibility' => 'shared',
        ])
        ->assertInvalid('responsible_stakeholder_id');

    expect(fn () => $engagement->registerDependency([
        'title' => 'Something vague',
        'party' => DependencyParty::Internal,
        'required_on' => today()->addWeek(),
    ]))->toThrow(ValidationException::class, 'Name the colleague');
});

test('a customer-owed item that is not shared never reaches the action list, so it is refused', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = dependencySetup();

    $this->actingAs($manager)
        ->post(route('engagements.dependencies.store', $engagement), [
            'title' => 'Credentials',
            'party' => DependencyParty::Customer->value,
            'responsible_stakeholder_id' => $contact->id,
            'required_on' => today()->addWeek()->toDateString(),
            'visibility' => 'internal',
        ])
        ->assertInvalid('visibility');
});

test('a stakeholder from another customer cannot be made responsible', function () {
    ['manager' => $manager, 'engagement' => $engagement] = dependencySetup();

    $elsewhere = Stakeholder::factory()->create(['name' => 'Someone Else']);

    $this->actingAs($manager)
        ->post(route('engagements.dependencies.store', $engagement), [
            'title' => 'Credentials',
            'party' => DependencyParty::Customer->value,
            'responsible_stakeholder_id' => $elsewhere->id,
            'required_on' => today()->addWeek()->toDateString(),
            'visibility' => 'shared',
        ])
        ->assertInvalid('responsible_stakeholder_id');
});

test('an outstanding item accrues delay day for day, attributed to the owing party', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact, 'milestone' => $milestone] = dependencySetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->subDays(6),
        'visibility' => 'shared',
    ], $manager);
    $dependency->syncLinks([['type' => BaselineItem::class, 'id' => $milestone->id]]);

    expect($dependency->delayDays())->toBe(6)
        ->and($dependency->isLate())->toBeTrue()
        ->and($dependency->attribution())->toBe(DependencyParty::Customer);

    $impact = $dependency->projectedImpact();

    expect($impact)->toHaveCount(1)
        ->and($impact[0]['baseline_date'])->toBe(today()->addDays(30)->toDateString())
        ->and($impact[0]['projected_date'])->toBe(today()->addDays(36)->toDateString())
        ->and($engagement->lateDependencies()->pluck('id')->all())->toBe([$dependency->id]);
});

test('an item that arrived on time causes no delay', function () {
    ['manager' => $manager, 'engagement' => $engagement] = dependencySetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Signed data processing agreement',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addDays(3),
        'visibility' => 'internal',
    ], $manager);

    expect($dependency->delayDays())->toBe(0)
        ->and($dependency->isLate())->toBeFalse();
});

test('the evidence trail records every chase and receiving stops the clock', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = dependencySetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->subDays(10),
        'visibility' => 'shared',
    ], $manager);

    $this->actingAs($manager)
        ->post(route('dependencies.events.store', $dependency), [
            'type' => DependencyEventType::Requested->value,
            'channel' => 'Email',
            'note' => 'Asked Anders for the credentials.',
            'occurred_at' => today()->subDays(12)->toDateString(),
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->post(route('dependencies.events.store', $dependency), [
            'type' => DependencyEventType::Reminded->value,
            'channel' => 'Steering call',
        ])
        ->assertRedirect();

    expect($dependency->refresh()->status)->toBe(DependencyStatus::Requested);

    $this->actingAs($manager)
        ->post(route('dependencies.events.store', $dependency), [
            'type' => DependencyEventType::Escalated->value,
            'note' => 'Raised with the sponsor.',
        ])
        ->assertRedirect();

    expect($dependency->refresh()->status)->toBe(DependencyStatus::Escalated)
        ->and($dependency->escalated_at)->not->toBeNull();

    $this->actingAs($manager)
        ->post(route('dependencies.events.store', $dependency), [
            'type' => DependencyEventType::Received->value,
            'occurred_at' => today()->subDays(4)->toDateString(),
        ])
        ->assertRedirect();

    $dependency->refresh();

    expect($dependency->status)->toBe(DependencyStatus::Received)
        ->and($dependency->settled_on?->toDateString())->toBe(today()->subDays(4)->toDateString())
        ->and($dependency->delayDays())->toBe(6)
        ->and($dependency->isLate())->toBeFalse()
        ->and($dependency->events)->toHaveCount(4)
        ->and(AuditLog::query()->where('action', 'dependency.received')->exists())->toBeTrue();
});

test('a settled dependency accepts no further trail entries or edits', function () {
    ['manager' => $manager, 'engagement' => $engagement] = dependencySetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Signed agreement',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addDays(3),
        'visibility' => 'internal',
    ], $manager);

    $dependency->recordEvent(DependencyEventType::Waived, ['note' => 'No longer needed.'], $manager);

    expect($dependency->refresh()->status)->toBe(DependencyStatus::Waived)
        ->and(fn () => $dependency->recordEvent(DependencyEventType::Reminded, [], $manager))
        ->toThrow(ValidationException::class, 'settled');

    $this->actingAs($manager)
        ->patch(route('dependencies.update', $dependency), [
            'title' => 'Signed agreement',
            'party' => DependencyParty::Internal->value,
            'responsible_user_id' => $manager->id,
            'required_on' => today()->addDays(20)->toDateString(),
            'visibility' => 'internal',
        ])
        ->assertInvalid('title');
});

test('an evidence trail entry cannot be dated in the future', function () {
    ['manager' => $manager, 'engagement' => $engagement] = dependencySetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Signed agreement',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addDays(3),
        'visibility' => 'internal',
    ], $manager);

    $this->actingAs($manager)
        ->post(route('dependencies.events.store', $dependency), [
            'type' => DependencyEventType::Reminded->value,
            'occurred_at' => today()->addDays(2)->toDateString(),
        ])
        ->assertInvalid('occurred_at');
});

test('the evidence trail is append-only', function () {
    ['manager' => $manager, 'engagement' => $engagement] = dependencySetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Signed agreement',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addDays(3),
        'visibility' => 'internal',
    ], $manager);

    $event = $dependency->recordEvent(DependencyEventType::Reminded, ['note' => 'Nudged.'], $manager);
    $stored = DependencyEvent::query()->whereKey($event->id)->sole();

    expect(fn () => $stored->update(['note' => 'Rewritten']))
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $stored->delete())
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $dependency->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

test('the register page summarises what is late and who owes it', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = dependencySetup();

    $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->subDays(5),
        'visibility' => 'shared',
    ], $manager);
    $engagement->registerDependency([
        'title' => 'Internal architecture sign-off',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addDays(10),
        'visibility' => 'internal',
    ], $manager);

    $this->actingAs($manager)
        ->get(route('engagements.dependencies.index', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/dependencies')
            ->has('dependencies', 2)
            ->where('summary.outstanding', 2)
            ->where('summary.late', 1)
            ->where('summary.customerOwed', 1)
            ->where('summary.worstDelayDays', 5)
            ->where('dependencies.0.responsibleName', 'Anders Vik')
            ->where('dependencies.0.attributionLabel', 'Customer'));
});

test('registering dependencies is a managing role', function () {
    ['engagement' => $engagement, 'organization' => $organization, 'contact' => $contact] = dependencySetup();

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->post(route('engagements.dependencies.store', $engagement), [
            'title' => 'Credentials',
            'party' => DependencyParty::Customer->value,
            'responsible_stakeholder_id' => $contact->id,
            'required_on' => today()->addWeek()->toDateString(),
            'visibility' => 'shared',
        ])
        ->assertForbidden();
});
