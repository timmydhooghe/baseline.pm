<?php

use App\Enums\DecisionStatus;
use App\Enums\DependencyEventType;
use App\Enums\DependencyParty;
use App\Enums\DependencyStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Enums\RiskRating;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Customer;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Stakeholder;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * Regressions found reviewing the governance ledgers: data the edit form
 * quietly dropped, records that stayed actionable after being replaced,
 * history that vanished on deploy, governance moves that left no trail,
 * ownerless dependencies, and lifecycle states that walked backwards.
 *
 * @return array<string, mixed>
 */
function hardeningSetup(): array
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
        'contact' => $contact,
    ];
}

test('editing a draft keeps the structured metadata the form did not carry', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $draft = $engagement->recordDecision([
        'title' => 'SSO is excluded from phase 1',
        'context' => 'Extracted from the steering call.',
        'participants' => [
            ['name' => 'Dana Mertens', 'affiliation' => null],
            ['name' => 'Anders Vik', 'affiliation' => null],
        ],
        'alternatives' => [['option' => 'Build it now', 'why_not' => 'Three days.']],
        'evidence' => [['label' => 'Transcript', 'url' => null]],
    ], $manager);

    // The form that adds the outcome and the date carries neither list.
    $this->actingAs($manager)
        ->patch(route('decisions.update', $draft), [
            'title' => $draft->title,
            'context' => $draft->context,
            'decision' => 'Excluded, revisited in phase 2.',
            'decided_on' => today()->toDateString(),
            'visibility' => RecordVisibility::Internal->value,
        ])
        ->assertRedirect();

    $draft->refresh();

    expect($draft->participants)->toHaveCount(2)
        ->and($draft->alternatives)->toHaveCount(1)
        ->and($draft->evidence)->toHaveCount(1)
        ->and($draft->decision)->toBe('Excluded, revisited in phase 2.');
});

test('a form that emptied a structured list says so and the list clears', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $draft = $engagement->recordDecision([
        'title' => 'SSO excluded',
        'context' => 'Context.',
        'participants' => [['name' => 'Dana Mertens', 'affiliation' => null]],
    ], $manager);

    $this->actingAs($manager)
        ->patch(route('decisions.update', $draft), [
            'title' => $draft->title,
            'context' => $draft->context,
            'visibility' => RecordVisibility::Internal->value,
            'participants_cleared' => true,
        ])
        ->assertRedirect();

    expect($draft->refresh()->participants)->toBe([]);
});

test('a superseded decision is read-only in the portal and cannot be acknowledged', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = hardeningSetup();

    $first = $engagement->recordDecision([
        'title' => 'SSO excluded from phase 1',
        'context' => 'Window too short.',
        'decision' => 'Excluded.',
        'decided_on' => today()->subMonth(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);
    $first->confirm($manager);
    $first->refresh();

    $second = $engagement->recordDecision([
        'title' => 'SSO ships in phase 2',
        'context' => 'Budget freed up.',
        'decision' => 'SSO ships in phase 2.',
        'decided_on' => today(),
        'supersedes_id' => $first->id,
        'visibility' => RecordVisibility::Shared,
    ], $manager);
    $second->confirm($manager);

    expect($first->refresh()->status)->toBe(DecisionStatus::Superseded);

    $parameters = [
        'decision' => $first->id,
        'stakeholder' => $contact->id,
        'snapshot' => $first->customer_snapshot_id,
    ];

    $this->get(URL::signedRoute('portal.decisions.show', $parameters))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('superseded', true)
            ->where('canAcknowledge', false));

    $this->post(URL::signedRoute('portal.decisions.acknowledge', $parameters), [])
        ->assertNotFound();

    expect($first->refresh()->acknowledged_at)->toBeNull()
        ->and(fn () => $first->acknowledge($contact))
        ->toThrow(ValidationException::class, 'replaced by a later one');
});

test('audit history recorded before the engagement column existed is backfilled', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $this->actingAs($manager);

    $baseline = Baseline::factory()->for($engagement->organization)->for($engagement)->create();
    $item = BaselineItem::factory()->for($engagement->organization)->for($baseline)->completeDeliverable()->create();
    $engagement->registerRisk(['title' => 'Migration data unreliable'], $manager);

    // Exactly the state a pre-migration database is in.
    DB::table('audit_logs')->update(['engagement_id' => null]);

    $migration = require database_path('migrations/2026_08_09_160000_add_engagement_id_to_audit_logs_table.php');
    $migration->backfill();

    $resolved = fn (string $subjectId): ?string => DB::table('audit_logs')
        ->where('subject_id', $subjectId)
        ->value('engagement_id');

    expect($resolved($engagement->id))->toBe($engagement->id)
        ->and($resolved($baseline->id))->toBe($engagement->id)
        ->and($resolved($item->id))->toBe($engagement->id)
        ->and(DB::table('audit_logs')->whereNull('engagement_id')->count())
        ->toBeLessThan(DB::table('audit_logs')->count());
});

test('every governance edit lands on the trail', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = hardeningSetup();

    $risk = $engagement->registerRisk(['title' => 'Migration data unreliable'], $manager);

    // A rating that did not move, but an owner and a plan that did.
    $risk->reassess(['mitigation' => 'Dry run in week 3.', 'owner_id' => $manager->id], $manager);

    $decision = $engagement->recordDecision(['title' => 'Draft', 'context' => 'Context.'], $manager);

    $this->actingAs($manager)
        ->patch(route('decisions.update', $decision), [
            'title' => 'Draft, retitled',
            'context' => 'Context.',
            'visibility' => RecordVisibility::Internal->value,
        ])
        ->assertRedirect();

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->addWeek(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);

    $this->actingAs($manager)
        ->patch(route('dependencies.update', $dependency), [
            'title' => 'Production database credentials',
            'party' => DependencyParty::Customer->value,
            'responsible_stakeholder_id' => $contact->id,
            'required_on' => today()->addDays(10)->toDateString(),
            'visibility' => RecordVisibility::Shared->value,
        ])
        ->assertRedirect();

    $risk->syncLinks([['type' => Dependency::class, 'id' => $dependency->id]], $manager);

    $this->actingAs($manager)
        ->delete(route('decisions.destroy', $decision))
        ->assertRedirect();

    $actions = AuditLog::query()->pluck('action');

    expect($actions)->toContain('risk.updated')
        ->and($actions)->toContain('decision.updated')
        ->and($actions)->toContain('dependency.updated')
        ->and($actions)->toContain('risk.links_updated')
        ->and($actions)->toContain('decision.draft_discarded');
});

test('a dependency keeps naming who owed it after that person is removed', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = hardeningSetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->addWeek(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);

    expect($dependency->responsible_name)->toBe('Anders Vik');

    $contact->delete();
    $dependency->refresh();

    expect($dependency->responsible_stakeholder_id)->toBeNull()
        ->and($dependency->responsibleName())->toBe('Anders Vik')
        ->and($dependency->needsReassignment())->toBeTrue()
        ->and(fn () => $dependency->update(['title' => 'Still needed']))
        ->toThrow(ValidationException::class, 'Name the customer stakeholder');

    $this->actingAs($manager)
        ->get(route('engagements.dependencies.index', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.unowned', 1)
            ->where('dependencies.0.needsReassignment', true)
            ->where('dependencies.0.responsibleName', 'Anders Vik'));
});

test('an item whose owner left can still be closed out', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = hardeningSetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->subDays(3),
        'visibility' => RecordVisibility::Shared,
    ], $manager);

    $contact->delete();

    $dependency->refresh()->recordEvent(DependencyEventType::Received, [], $manager);

    expect($dependency->refresh()->status)->toBe(DependencyStatus::Received)
        ->and($dependency->responsibleName())->toBe('Anders Vik');
});

test('a decision already superseded is never offered or accepted as a target', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $first = $engagement->recordDecision([
        'title' => 'First',
        'context' => 'Context.',
        'decision' => 'Decided.',
        'decided_on' => today()->subMonth(),
    ], $manager);
    $first->confirm($manager);

    $second = $engagement->recordDecision([
        'title' => 'Second',
        'context' => 'Context.',
        'decision' => 'Decided again.',
        'decided_on' => today()->subWeek(),
        'supersedes_id' => $first->id,
    ], $manager);
    $second->confirm($manager);

    $third = $engagement->recordDecision(['title' => 'Third', 'context' => 'Context.'], $manager);

    $this->actingAs($manager)
        ->patch(route('decisions.update', $third), [
            'title' => 'Third',
            'context' => 'Context.',
            'visibility' => RecordVisibility::Internal->value,
            'supersedes_id' => $first->id,
        ])
        ->assertInvalid('supersedes_id');

    $this->actingAs($manager)
        ->get(route('decisions.show', $third))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('options.supersedable', 1)
            ->where('options.supersedable.0.value', $second->id));
});

test('two drafts cannot claim the same predecessor', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $target = $engagement->recordDecision([
        'title' => 'Original',
        'context' => 'Context.',
        'decision' => 'Decided.',
        'decided_on' => today()->subMonth(),
    ], $manager);
    $target->confirm($manager);

    $engagement->recordDecision([
        'title' => 'Replacement',
        'context' => 'Context.',
        'decision' => 'Replaced.',
        'decided_on' => today(),
        'supersedes_id' => $target->id,
    ], $manager);

    $rival = $engagement->recordDecision(['title' => 'Rival', 'context' => 'Context.'], $manager);

    $this->actingAs($manager)
        ->patch(route('decisions.update', $rival), [
            'title' => 'Rival',
            'context' => 'Context.',
            'visibility' => RecordVisibility::Internal->value,
            'supersedes_id' => $target->id,
        ])
        ->assertInvalid('supersedes_id');

    /*
     * The claim is refused with a sentence rather than surfacing as the
     * unique-constraint violation the reference would otherwise raise at
     * confirmation time, and the original claim stands untouched.
     */
    expect($rival->refresh()->supersedes_id)->toBeNull()
        ->and(Decision::query()->where('supersedes_id', $target->id)->sole()->title)
        ->toBe('Replacement');
});

test('a blocked deliverable gets its projected date too', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $baseline = Baseline::factory()->for($engagement->organization)->for($engagement)->create();
    $item = BaselineItem::factory()->for($engagement->organization)->for($baseline)->completeDeliverable()->create();

    $deliverable = Deliverable::factory()->create([
        'organization_id' => $engagement->organization_id,
        'engagement_id' => $engagement->id,
        'baseline_item_id' => $item->id,
        'forecast_date' => today()->addDays(20),
    ]);

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->subDays(4),
        'visibility' => RecordVisibility::Internal,
    ], $manager);
    $dependency->syncLinks([['type' => Deliverable::class, 'id' => $deliverable->id]], $manager);

    $impact = $dependency->projectedImpact();

    expect($impact)->toHaveCount(1)
        ->and($impact[0]['baseline_date'])->toBe(today()->addDays(20)->toDateString())
        ->and($impact[0]['projected_date'])->toBe(today()->addDays(24)->toDateString());
});

test('the evidence trail records a late request without demoting an escalation', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->subDays(10),
        'visibility' => RecordVisibility::Internal,
    ], $manager);

    $dependency->recordEvent(DependencyEventType::Escalated, [], $manager);
    $escalatedAt = $dependency->refresh()->escalated_at;

    $dependency->recordEvent(DependencyEventType::Requested, ['note' => 'Formal request finally logged.'], $manager);

    $dependency->refresh();

    expect($dependency->status)->toBe(DependencyStatus::Escalated)
        ->and($dependency->escalated_at?->toIso8601String())->toBe($escalatedAt?->toIso8601String())
        ->and($dependency->events)->toHaveCount(2);
});

test('the audit trail link is offered only to roles that may open it', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'organization' => $organization] = hardeningSetup();

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('engagements.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.viewAudit', false));

    $this->actingAs($manager)
        ->get(route('engagements.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.viewAudit', true));
});

test('a risk raised high stays escalated in the register summary', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $engagement->registerRisk([
        'title' => 'Migration data unreliable',
        'probability' => RiskRating::High,
        'impact' => RiskRating::High,
    ], $manager);

    expect($engagement->escalatedRisks())->toHaveCount(1);
});
