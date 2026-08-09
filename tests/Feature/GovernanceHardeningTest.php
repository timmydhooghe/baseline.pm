<?php

use App\Enums\BaselineStatus;
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

test('a draft confirmed mid-edit refuses the stale write', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded',
        'context' => 'Context.',
        'decision' => 'Excluded.',
        'decided_on' => today(),
    ], $manager);

    // The record as another request loaded it, before the confirmation.
    $stale = Decision::query()->whereKey($decision->id)->sole();

    $decision->confirm($manager);

    expect(fn () => $stale->updateDraft(fn (): array => ['title' => 'Rewritten'], []))
        ->toThrow(ValidationException::class, 'confirmed while you were working on it')
        ->and($decision->refresh()->title)->toBe('SSO excluded');
});

test('a draft confirmed mid-delete is kept', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded',
        'context' => 'Context.',
        'decision' => 'Excluded.',
        'decided_on' => today(),
    ], $manager);

    $stale = Decision::query()->whereKey($decision->id)->sole();

    $decision->confirm($manager);

    expect(fn () => $stale->discardDraft($manager))
        ->toThrow(ValidationException::class, 'confirmed while you were working on it')
        ->and(Decision::query()->whereKey($decision->id)->exists())->toBeTrue();
});

test('a dependency settled mid-edit refuses the stale write', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addWeek(),
        'visibility' => RecordVisibility::Internal,
    ], $manager);

    $stale = Dependency::query()->whereKey($dependency->id)->sole();

    $dependency->recordEvent(DependencyEventType::Received, [], $manager);

    expect(fn () => $stale->updateOutstanding(fn (): array => ['title' => 'Rewritten'], []))
        ->toThrow(ValidationException::class, 'settled while you were working on it');
});

test('a flagged dependency can actually be reassigned', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'customer' => $customer, 'contact' => $contact] = hardeningSetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->addWeek(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);

    $contact->delete();

    expect($dependency->refresh()->needsReassignment())->toBeTrue();

    $successor = Stakeholder::factory()
        ->for($engagement->organization)
        ->for($customer)
        ->role(StakeholderRole::ProjectManager)
        ->create(['name' => 'Petra Molnar']);

    $this->actingAs($manager)
        ->get(route('dependencies.show', $dependency))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dependencies/show')
            ->where('can.update', true)
            ->has('options.stakeholders'));

    $this->actingAs($manager)
        ->patch(route('dependencies.update', $dependency), [
            'title' => 'Production database credentials',
            'party' => DependencyParty::Customer->value,
            'responsible_stakeholder_id' => $successor->id,
            'required_on' => today()->addWeek()->toDateString(),
            'visibility' => RecordVisibility::Shared->value,
        ])
        ->assertRedirect();

    $dependency->refresh();

    expect($dependency->needsReassignment())->toBeFalse()
        ->and($dependency->responsibleName())->toBe('Petra Molnar');
});

test('a superseded record stops asking to be acknowledged', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $first = $engagement->recordDecision([
        'title' => 'First',
        'context' => 'Context.',
        'decision' => 'Decided.',
        'decided_on' => today()->subMonth(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);
    $first->confirm($manager);

    $second = $engagement->recordDecision([
        'title' => 'Second',
        'context' => 'Context.',
        'decision' => 'Decided again.',
        'decided_on' => today(),
        'supersedes_id' => $first->id,
        'visibility' => RecordVisibility::Shared,
    ], $manager);
    $second->confirm($manager);

    $this->actingAs($manager);

    // Only the live record is still waiting on the customer.
    $this->get(route('engagements.decisions.index', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.awaitingAcknowledgement', 1));

    $this->get(route('engagements.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('governance.decisions.awaitingAcknowledgement', 1));

    $this->get(route('decisions.show', $first))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('acknowledgementLinks', 0));

    $this->get(route('decisions.show', $second))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('acknowledgementLinks', 1));
});

test('a link the picker no longer offers can still be posted back or removed', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $superseded = Baseline::factory()->for($engagement->organization)->for($engagement)->create(['version' => 1]);
    $old = BaselineItem::factory()->for($engagement->organization)->for($superseded)->completeDeliverable()->create([
        'title' => 'Item from version 1',
    ]);
    $superseded->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    $current = Baseline::factory()->for($engagement->organization)->for($engagement)->create(['version' => 2]);
    $current->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    $draft = $engagement->recordDecision(['title' => 'Draft', 'context' => 'Context.'], $manager);

    // The picker only offers the current version's items…
    $this->actingAs($manager)
        ->get(route('decisions.show', $draft))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('options.records', fn ($records) => collect($records)
                ->doesntContain(fn (array $record): bool => $record['id'] === $old->id)));

    // …but a link to the older item is accepted and readable back.
    $this->actingAs($manager)
        ->patch(route('decisions.update', $draft), [
            'title' => 'Draft',
            'context' => 'Context.',
            'visibility' => RecordVisibility::Internal->value,
            'links' => [['type' => BaselineItem::class, 'id' => $old->id]],
        ])
        ->assertRedirect();

    expect($draft->refresh()->links->sole()->describe()['title'])->toBe('Item from version 1');

    // And removing every chip clears it.
    $this->actingAs($manager)
        ->patch(route('decisions.update', $draft), [
            'title' => 'Draft',
            'context' => 'Context.',
            'visibility' => RecordVisibility::Internal->value,
        ])
        ->assertRedirect();

    expect($draft->refresh()->links)->toBeEmpty();
});

test('an enormous transcript line still proposes a draft that can be confirmed', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $line = 'Decision: '.str_repeat('we rebuild the importer and re-run the migration, ', 900);

    $this->actingAs($manager)
        ->post(route('engagements.decisions.transcript', $engagement), ['transcript' => $line])
        ->assertRedirect();

    $draft = Decision::query()->sole();

    expect(mb_strlen((string) $draft->decision))->toBeLessThanOrEqual(5000)
        ->and(mb_strlen($draft->context))->toBeLessThanOrEqual(5000);

    // The draft survives the edit that adds the date, then confirms.
    $this->actingAs($manager)
        ->patch(route('decisions.update', $draft), [
            'title' => $draft->title,
            'context' => $draft->context,
            'decision' => $draft->decision,
            'decided_on' => today()->toDateString(),
            'visibility' => RecordVisibility::Internal->value,
        ])
        ->assertValid()
        ->assertRedirect();

    $this->actingAs($manager)
        ->post(route('decisions.confirm', $draft))
        ->assertRedirect();

    expect($draft->refresh()->status)->toBe(DecisionStatus::Confirmed);
});

test('an evidence trail entry carries the link that proves it', function () {
    ['manager' => $manager, 'engagement' => $engagement] = hardeningSetup();

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addWeek(),
        'visibility' => RecordVisibility::Internal,
    ], $manager);

    $this->actingAs($manager)
        ->post(route('dependencies.events.store', $dependency), [
            'type' => DependencyEventType::Reminded->value,
            'channel' => 'Email',
            'note' => 'Chased again.',
            'evidence_url' => 'https://mail.example.test/thread/42',
        ])
        ->assertRedirect();

    $this->actingAs($manager)
        ->get(route('dependencies.show', $dependency))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.0.evidenceUrl', 'https://mail.example.test/thread/42'));
});
