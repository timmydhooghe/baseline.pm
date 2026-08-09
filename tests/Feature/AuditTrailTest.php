<?php

use App\Enums\DependencyParty;
use App\Enums\EngagementStatus;
use App\Enums\RiskRating;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\User;
use App\ValueObjects\Money;
use Inertia\Testing\AssertableInertia;

/**
 * FA-21: the audit log is append-only and linked from everywhere. These
 * cover the "from everywhere" half — every entry knows which engagement it
 * belongs to, so a trail can be read from the engagement and filtered down
 * to a single record. Append-only itself is covered in AuditLogTest.
 */
function auditSetup(): array
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

    return [
        'manager' => $manager,
        'organization' => $organization,
        'engagement' => $engagement,
    ];
}

test('governance entries carry the engagement they belong to', function () {
    ['manager' => $manager, 'engagement' => $engagement] = auditSetup();

    $this->actingAs($manager);

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded',
        'context' => 'Phase 1 window.',
        'decision' => 'Excluded.',
        'decided_on' => today(),
    ], $manager);

    $risk = $engagement->registerRisk(['title' => 'Migration data unreliable'], $manager);

    $dependency = $engagement->registerDependency([
        'title' => 'Credentials',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addWeek(),
        'visibility' => 'internal',
    ], $manager);

    $entries = AuditLog::query()
        ->whereIn('subject_id', [$decision->id, $risk->id, $dependency->id])
        ->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('engagement_id')->unique()->all())->toBe([$engagement->id]);
});

test('records hanging off a baseline inherit its engagement', function () {
    ['manager' => $manager, 'engagement' => $engagement] = auditSetup();

    $this->actingAs($manager);

    $baseline = Baseline::factory()->for($engagement->organization)->for($engagement)->create();
    $item = BaselineItem::factory()->for($engagement->organization)->for($baseline)->completeDeliverable()->create();

    $entry = AuditLog::query()
        ->where('subject_id', $item->id)
        ->where('action', 'created')
        ->sole();

    expect($entry->engagement_id)->toBe($engagement->id);
});

test('organization-level records belong to no engagement', function () {
    ['manager' => $manager, 'organization' => $organization] = auditSetup();

    $this->actingAs($manager);

    $customer = Customer::factory()->for($organization)->create(['name' => 'Second Customer']);

    $entry = AuditLog::query()
        ->where('subject_id', $customer->id)
        ->where('action', 'created')
        ->sole();

    expect($entry->engagement_id)->toBeNull();
});

test('the trail reads in order and filters down to one record', function () {
    ['manager' => $manager, 'engagement' => $engagement] = auditSetup();

    $this->actingAs($manager);

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded',
        'context' => 'Phase 1 window.',
        'decision' => 'Excluded.',
        'decided_on' => today(),
    ], $manager);
    $decision->confirm($manager);

    $engagement->registerRisk(['title' => 'Migration data unreliable'], $manager);

    $this->get(route('engagements.audit.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/audit')
            ->where('entries.data.0.action', 'risk.registered')
            ->has('actions'));

    $this->get(route('engagements.audit.show', ['engagement' => $engagement, 'subject' => $decision->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.data', 2)
            ->where('filters.subject', $decision->id));

    $this->get(route('engagements.audit.show', ['engagement' => $engagement, 'action' => 'decision.']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 2));
});

test('another engagement never appears in this engagement trail', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'organization' => $organization] = auditSetup();

    $this->actingAs($manager);

    $other = Engagement::factory()
        ->for($organization)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'Other rollout']);

    $other->registerRisk(['title' => 'Someone else problem'], $manager);
    $engagement->registerRisk(['title' => 'Our problem'], $manager);

    $this->get(route('engagements.audit.show', ['engagement' => $engagement, 'action' => 'risk.']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.payload.risk', 'Our problem'));
});

test('the trail is visible to managing roles only', function () {
    ['engagement' => $engagement, 'organization' => $organization] = auditSetup();

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('engagements.audit.show', $engagement))
        ->assertForbidden();
});

test('the engagement page links to every ledger and its trail', function () {
    ['manager' => $manager, 'engagement' => $engagement] = auditSetup();

    $engagement->registerRisk([
        'title' => 'Migration data unreliable',
        'probability' => RiskRating::High,
        'impact' => RiskRating::High,
    ], $manager);
    $engagement->recordDecision(['title' => 'Draft call', 'context' => 'Context.'], $manager);

    $this->actingAs($manager)
        ->get(route('engagements.show', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('governance.decisions.total', 1)
            ->where('governance.decisions.drafts', 1)
            ->where('governance.risks.live', 1)
            ->where('governance.risks.escalated', 1)
            ->where('governance.dependencies.outstanding', 0)
            ->where('governance.auditEntries', 3));
});
