<?php

use App\Enums\BaselineStatus;
use App\Enums\DecisionSource;
use App\Enums\DecisionStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Customer;
use App\Models\Decision;
use App\Models\Engagement;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * A delivery manager on an active engagement with an approved baseline
 * carrying one deliverable, plus a customer with a contact. All free text is
 * fixed so leakage assertions can scan whole payloads.
 *
 * @return array<string, mixed>
 */
function decisionSetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create(['name' => 'Dana Mertens']);
    $organization = $manager->organization;

    $version = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
    ]);

    $customer = Customer::factory()->for($organization)->create(['name' => 'Acme Industries']);
    $engagement = Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'rate_card_version_id' => $version->id,
        'contract_value' => Money::fromCents(2000000),
    ]);

    $item = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Checkout flow',
        'value' => Money::fromCents(2000000),
        'position' => 1,
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
        'item' => $item,
        'contact' => $contact,
    ];
}

/**
 * The structured payload a decision form posts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function decisionPayload(array $overrides = []): array
{
    return [
        'title' => 'SSO excluded from phase 1',
        'context' => 'The customer IdP is Azure AD; wiring it up lands outside the phase 1 window.',
        'decision' => 'SSO is excluded from phase 1 and revisited in phase 2.',
        'alternatives' => [
            ['option' => 'Build SSO now', 'why_not' => 'Three days we do not have before go-live.'],
            ['option' => 'Third-party broker', 'why_not' => 'Adds a vendor the customer has not approved.'],
        ],
        'participants' => [
            ['name' => 'Dana Mertens', 'affiliation' => 'Baseline'],
            ['name' => 'Anders Vik', 'affiliation' => 'Acme Industries'],
        ],
        'evidence' => [
            ['label' => 'Steering minutes', 'url' => 'https://example.test/minutes'],
        ],
        'impact_scope' => 'Authentication stays local for phase 1.',
        'impact_budget' => 4500,
        'impact_timeline_days' => -3,
        'visibility' => RecordVisibility::Internal->value,
        ...$overrides,
    ];
}

test('a manager drafts a decision with structured context, alternatives and linked records', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'item' => $item] = decisionSetup();

    $this->actingAs($manager)
        ->post(route('engagements.decisions.store', $engagement), decisionPayload([
            'links' => [['type' => BaselineItem::class, 'id' => $item->id]],
        ]))
        ->assertRedirect();

    $decision = Decision::query()->sole();

    expect($decision->status)->toBe(DecisionStatus::Draft)
        ->and($decision->source)->toBe(DecisionSource::Manual)
        ->and($decision->alternatives)->toHaveCount(2)
        ->and($decision->alternatives[0]['why_not'])->toBe('Three days we do not have before go-live.')
        ->and($decision->participants)->toHaveCount(2)
        ->and($decision->impact_budget?->amount)->toBe(450000)
        ->and($decision->impact_timeline_days)->toBe(-3)
        ->and($decision->links->sole()->linked_id)->toBe($item->id)
        ->and($decision->created_by)->toBe($manager->id);
});

test('a decision cannot link a record from another engagement', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $elsewhere = BaselineItem::factory()->completeDeliverable()->create();

    $this->actingAs($manager)
        ->post(route('engagements.decisions.store', $engagement), decisionPayload([
            'links' => [['type' => BaselineItem::class, 'id' => $elsewhere->id]],
        ]))
        ->assertInvalid('links');

    expect(Decision::query()->count())->toBe(0);
});

test('confirming requires an outcome and the date it was taken', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $decision = $engagement->recordDecision(['title' => 'Open question', 'context' => 'Raised in steering.'], $manager);

    expect(fn () => $decision->confirm($manager))
        ->toThrow(ValidationException::class, 'Record what was decided');

    $decision->update(['decision' => 'We ship without it.']);

    expect(fn () => $decision->confirm($manager))
        ->toThrow(ValidationException::class, 'Record when the decision was taken');
});

test('a confirmed decision is immutable and can only be superseded', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded',
        'context' => 'Phase 1 window.',
        'decision' => 'Excluded.',
        'decided_on' => today()->subDay(),
    ], $manager);

    $decision->confirm($manager);

    expect(fn () => $decision->update(['title' => 'Rewritten']))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $decision->delete())
        ->toThrow(LogicException::class, 'Only draft decisions can be deleted');

    $this->actingAs($manager)
        ->patch(route('decisions.update', $decision), decisionPayload())
        ->assertInvalid('title');
});

test('confirming a superseding decision closes the chain behind it', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $first = $engagement->recordDecision([
        'title' => 'SSO excluded from phase 1',
        'context' => 'Window too short.',
        'decision' => 'Excluded.',
        'decided_on' => today()->subMonth(),
    ], $manager);
    $first->confirm($manager);

    $second = $engagement->recordDecision([
        'title' => 'SSO added to phase 2',
        'context' => 'Budget freed up.',
        'decision' => 'SSO ships in phase 2.',
        'decided_on' => today(),
        'supersedes_id' => $first->id,
    ], $manager);
    $second->confirm($manager);

    expect($first->refresh()->status)->toBe(DecisionStatus::Superseded)
        ->and($second->refresh()->status)->toBe(DecisionStatus::Confirmed)
        ->and(collect($second->supersedesChain())->pluck('id')->all())->toBe([$first->id])
        ->and(AuditLog::query()->where('action', 'decision.superseded')->exists())->toBeTrue();
});

test('a decision cannot supersede a draft', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $draft = $engagement->recordDecision(['title' => 'Draft', 'context' => 'Context.'], $manager);

    $superseding = $engagement->recordDecision([
        'title' => 'Later',
        'context' => 'Context.',
        'decision' => 'Something.',
        'decided_on' => today(),
        'supersedes_id' => $draft->id,
    ], $manager);

    expect(fn () => $superseding->confirm($manager))
        ->toThrow(ValidationException::class, 'not on the ledger yet');
});

test('the ledger is searchable by the question a reader would ask', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $engagement->recordDecision([
        'title' => 'Authentication approach',
        'context' => 'Whether single sign-on ships in phase 1.',
        'decision' => 'SSO is excluded from phase 1.',
        'decided_on' => today(),
    ], $manager);
    $engagement->recordDecision([
        'title' => 'Reporting cadence',
        'context' => 'How often finance receives figures.',
        'decision' => 'Monthly.',
        'decided_on' => today(),
    ], $manager);

    $this->actingAs($manager)
        ->get(route('engagements.decisions.index', ['engagement' => $engagement, 'q' => 'single sign-on']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/decisions')
            ->has('decisions', 1)
            ->where('decisions.0.title', 'Authentication approach'));
});

test('a transcript proposes drafts that carry their excerpt and nobody confirmed', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $transcript = <<<'TXT'
    [10:02] Dana Mertens: Morning all, let's talk about single sign-on.
    Anders Vik: Our IdP is Azure AD, so it is doable, but it is three days of work.
    Dana Mertens: Three days we do not have in phase 1.
    Decision: SSO is excluded from phase 1 and revisited in phase 2.
    Anders Vik: What about the reporting module?
    Dana Mertens: We agreed last week that reporting ships with milestone 2.
    Anders Vik: I wonder whether we should look at caching at some point.
    TXT;

    $this->actingAs($manager)
        ->post(route('engagements.decisions.transcript', $engagement), ['transcript' => $transcript])
        ->assertRedirect(route('engagements.decisions.index', $engagement));

    $drafts = Decision::query()->orderBy('created_at')->get();

    expect($drafts)->toHaveCount(2)
        ->and($drafts->pluck('status')->unique()->all())->toBe([DecisionStatus::Draft])
        ->and($drafts->pluck('source')->unique()->all())->toBe([DecisionSource::Transcript])
        ->and($drafts[0]->decision)->toBe('SSO is excluded from phase 1 and revisited in phase 2.')
        ->and($drafts[0]->transcript_excerpt)->toContain('Three days we do not have in phase 1.')
        ->and(collect($drafts[0]->participants)->pluck('name')->all())->toBe(['Dana Mertens', 'Anders Vik'])
        ->and($drafts[1]->decision)->toBe('We agreed last week that reporting ships with milestone 2.');
});

test('a transcript that closed nothing proposes nothing', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $this->actingAs($manager)
        ->post(route('engagements.decisions.transcript', $engagement), [
            'transcript' => "Dana Mertens: I wonder whether caching would help.\nAnders Vik: Maybe, let us look at it next month.",
        ])
        ->assertRedirect();

    expect(Decision::query()->count())->toBe(0);
});

test('a shared decision freezes a customer payload that carries no budget impact', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded from phase 1',
        'context' => 'Phase 1 window is too short.',
        'decision' => 'Excluded, revisited in phase 2.',
        'decided_on' => today(),
        'impact_budget' => Money::fromCents(450000),
        'impact_scope' => 'Authentication stays local.',
        'visibility' => RecordVisibility::Shared,
    ], $manager);

    $decision->confirm($manager);

    $snapshot = Snapshot::query()->whereKey($decision->refresh()->customer_snapshot_id)->sole();
    $encoded = json_encode($snapshot->payload);

    expect($snapshot->payload['kind'])->toBe('customer_decision')
        ->and($snapshot->payload['impact'])->toHaveKey('scope')
        ->and($snapshot->payload['impact'])->not->toHaveKey('budget')
        ->and($encoded)->not->toContain('4500')
        ->and($encoded)->not->toContain('450000')
        ->and($snapshot->verifyIntegrity())->toBeTrue();
});

test('an internal decision freezes no customer payload at all', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $decision = $engagement->recordDecision([
        'title' => 'Internal staffing call',
        'context' => 'Who leads the migration.',
        'decision' => 'Dana leads it.',
        'decided_on' => today(),
    ], $manager);

    $decision->confirm($manager);

    expect($decision->refresh()->customer_snapshot_id)->toBeNull()
        ->and(Snapshot::query()->count())->toBe(0);
});

test('the customer acknowledges a shared decision through a signed link, once', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = decisionSetup();

    $decision = $engagement->recordDecision([
        'title' => 'SSO excluded from phase 1',
        'context' => 'Phase 1 window is too short.',
        'decision' => 'Excluded, revisited in phase 2.',
        'decided_on' => today(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);
    $decision->confirm($manager);
    $decision->refresh();

    $url = URL::signedRoute('portal.decisions.show', [
        'decision' => $decision->id,
        'stakeholder' => $contact->id,
        'snapshot' => $decision->customer_snapshot_id,
    ]);

    $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('portal/decision')
        ->where('canAcknowledge', true)
        ->where('record.decision.title', 'SSO excluded from phase 1'));

    $acknowledge = URL::signedRoute('portal.decisions.acknowledge', [
        'decision' => $decision->id,
        'stakeholder' => $contact->id,
        'snapshot' => $decision->customer_snapshot_id,
    ]);

    $this->post($acknowledge, ['comment' => 'Understood, thanks.'])->assertRedirect();

    $decision->refresh();

    expect($decision->acknowledged_at)->not->toBeNull()
        ->and($decision->acknowledged_by_name)->toBe('Anders Vik')
        ->and($decision->acknowledgement_comment)->toBe('Understood, thanks.')
        ->and(AuditLog::query()->where('action', 'decision.acknowledged')->exists())->toBeTrue();

    $this->post($acknowledge, [])->assertSessionHasErrors('acknowledgement');
});

test('an unsigned acknowledgement link is refused', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = decisionSetup();

    $decision = $engagement->recordDecision([
        'title' => 'Shared call',
        'context' => 'Context.',
        'decision' => 'Decided.',
        'decided_on' => today(),
        'visibility' => RecordVisibility::Shared,
    ], $manager);
    $decision->confirm($manager);

    $this->get(route('portal.decisions.show', [
        'decision' => $decision->id,
        'stakeholder' => $contact->id,
    ]))->assertForbidden();
});

test('an internal decision is never reachable from the portal', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = decisionSetup();

    $decision = $engagement->recordDecision([
        'title' => 'Internal call',
        'context' => 'Context.',
        'decision' => 'Decided.',
        'decided_on' => today(),
    ], $manager);
    $decision->confirm($manager);

    $this->get(URL::signedRoute('portal.decisions.show', [
        'decision' => $decision->id,
        'stakeholder' => $contact->id,
        'snapshot' => 'missing',
    ]))->assertForbidden();
});

test('recording decisions is a managing role, reading them is not', function () {
    ['engagement' => $engagement, 'organization' => $organization, 'manager' => $manager] = decisionSetup();

    $engagement->recordDecision([
        'title' => 'On record',
        'context' => 'Context.',
        'decision' => 'Decided.',
        'decided_on' => today(),
    ], $manager)->confirm($manager);

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();
    $viewer = User::factory()->for($organization)->role(UserRole::PortfolioViewer)->create();

    $this->actingAs($member)
        ->post(route('engagements.decisions.store', $engagement), decisionPayload())
        ->assertForbidden();

    $engagement->recordDecision(['title' => 'Still a draft', 'context' => 'Context.'], $manager);

    $this->actingAs($viewer)
        ->get(route('engagements.decisions.index', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('decisions', 2)
            ->where('counts.total', 2)
            ->where('counts.drafts', 1)
            ->where('counts.awaitingAcknowledgement', 0)
            ->where('can.create', false));
});

test('an archived engagement records no new decisions', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $engagement->forceFill(['status' => EngagementStatus::Archived])->save();

    $this->actingAs($manager)
        ->post(route('engagements.decisions.store', $engagement), decisionPayload())
        ->assertForbidden();
});

test('a draft can be discarded but its audit trail remains', function () {
    ['manager' => $manager, 'engagement' => $engagement] = decisionSetup();

    $decision = $engagement->recordDecision(['title' => 'Never mind', 'context' => 'Context.'], $manager);

    $this->actingAs($manager)
        ->delete(route('decisions.destroy', $decision))
        ->assertRedirect(route('engagements.decisions.index', $engagement));

    expect(Decision::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'decision.drafted')->exists())->toBeTrue();
});
