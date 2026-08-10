<?php

use App\Enums\AcceptanceDecision;
use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestDecision;
use App\Enums\ChangeRequestOrigin;
use App\Enums\DeliverableConfidence;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\EvidenceKind;
use App\Enums\FinalAcceptanceStatus;
use App\Enums\RecordVisibility;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineItem;
use App\Models\Customer;
use App\Models\Deliverable;
use App\Models\DeliverableEvidence;
use App\Models\Engagement;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\DeliverableSubmitted;
use App\Notifications\FinalAcceptanceSubmitted;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * A delivery manager on an active engagement executing against approved
 * baseline v1: two valued deliverables summing to the €20,000 contract
 * ('Checkout flow' €12,000 with two criteria, 'Reporting pack' €8,000 with
 * one), a dated go-live milestone and an approver stakeholder. Records are
 * provisioned exactly as approval would. All free text is fixed so leakage
 * assertions can scan whole payloads.
 *
 * @return array<string, mixed>
 */
function acceptanceSetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
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

    $owner = User::factory()->for($organization)->create(['name' => 'Delivery Owner']);

    $checkout = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Checkout flow',
        'description' => 'The full purchase funnel.',
        'value' => Money::fromCents(1200000),
        'position' => 1,
        'owner_id' => $owner->id,
        'acceptance_criteria' => [
            ['criterion' => 'All flows pass UAT', 'verification_method' => 'UAT sign-off document'],
            ['criterion' => 'Deploys cleanly to production', 'verification_method' => 'Release log'],
        ],
    ]);
    $reporting = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Reporting pack',
        'description' => 'Weekly figures for finance.',
        'value' => Money::fromCents(800000),
        'position' => 2,
        'owner_id' => $owner->id,
    ]);
    $milestone = BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live',
        'description' => null,
        'position' => 1,
        'baseline_date' => today()->addDays(30),
    ]);

    BaselineAllocation::factory()->for($organization)->for($baseline)->create([
        'baseline_item_id' => $checkout->id,
        'rate_card_role_id' => $version->roles->sole()->id,
        'days' => '10',
    ]);

    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    Deliverable::provisionForBaseline($baseline);

    $approver = Stakeholder::factory()
        ->for($organization)
        ->for($customer)
        ->role(StakeholderRole::Approver)
        ->create(['name' => 'Anders Vik']);

    return [
        'manager' => $manager,
        'organization' => $organization,
        'engagement' => $engagement,
        'baseline' => $baseline,
        'checkout' => $checkout,
        'reporting' => $reporting,
        'milestone' => $milestone,
        'approver' => $approver,
        'developer' => $version->roles->sole(),
        'checkoutRecord' => Deliverable::query()->where('baseline_item_id', $checkout->id)->sole(),
        'reportingRecord' => Deliverable::query()->where('baseline_item_id', $reporting->id)->sole(),
    ];
}

/**
 * Add a shared release as evidence and link it to every criterion, the way
 * the record UI would before submission.
 */
function linkCriterionEvidence(Deliverable $record, User $manager): DeliverableEvidence
{
    $evidence = $record->evidence()->create([
        'organization_id' => $record->organization_id,
        'kind' => EvidenceKind::Release,
        'label' => 'Sprint 12 build',
        'url' => 'https://example.test/build',
        'visibility' => RecordVisibility::Shared,
        'added_by' => $manager->id,
    ]);

    $record->update([
        'criteria_state' => array_map(
            fn (): array => ['evidence_id' => $evidence->id, 'visibility' => RecordVisibility::Shared->value],
            $record->baselineItem->acceptance_criteria ?? [],
        ),
    ]);

    return $evidence;
}

/**
 * Drive a record through the whole flow to a signed acceptance.
 */
function signOffDeliverable(Deliverable $record, User $manager, Stakeholder $approver): void
{
    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(7), $manager);
    $record->refresh()->recordResponse($approver, AcceptanceDecision::Accepted);
    $record->refresh();
}

test('approving a baseline provisions a record per deliverable item', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()
        ->for($manager->organization)
        ->status(EngagementStatus::AwaitingBaselineApproval)
        ->create();
    $baseline = Baseline::factory()
        ->for($manager->organization)
        ->for($engagement)
        ->create();
    $item = BaselineItem::factory()->for($manager->organization)->for($baseline)->completeDeliverable()->create();
    BaselineItem::factory()->for($manager->organization)->for($baseline)->completeMilestone()->create();

    $baseline->forceFill(['status' => BaselineStatus::AwaitingApproval, 'submitted_at' => now()])->save();
    $baseline->approve();

    $record = Deliverable::query()->where('baseline_item_id', $item->id)->sole();

    // One record for the deliverable, none for the milestone; the criteria
    // state starts aligned with the item's criteria — unevidenced, shared.
    expect(Deliverable::query()->count())->toBe(1)
        ->and($record->status)->toBe(DeliverableStatus::InProgress)
        ->and($record->engagement_id)->toBe($engagement->id)
        ->and($record->criteria_state)->toHaveCount(count($item->acceptance_criteria))
        ->and($record->criteria_state[0])->toBe(['evidence_id' => null, 'visibility' => 'shared'])
        ->and($record->versions()->count())->toBe(1);
});

test('execution updates keep the record current', function () {
    $setup = acceptanceSetup();
    ['manager' => $manager, 'milestone' => $milestone, 'checkoutRecord' => $record] = $setup;

    $evidence = linkCriterionEvidence($record, $manager);

    $this->actingAs($manager)
        ->patch(route('deliverables.update', $record), [
            'progress' => 70,
            'confidence' => 'high',
            'forecast_date' => today()->addDays(21)->toDateString(),
            'milestone_item_id' => $milestone->id,
            'criteria' => [
                ['evidence_id' => $evidence->id, 'visibility' => 'shared'],
                ['evidence_id' => null, 'visibility' => 'internal'],
            ],
        ])
        ->assertRedirect(route('deliverables.show', $record));

    $record->refresh();

    expect($record->progress)->toBe(70)
        ->and($record->confidence)->toBe(DeliverableConfidence::High)
        ->and($record->forecast_date?->toDateString())->toBe(today()->addDays(21)->toDateString())
        ->and($record->milestone_item_id)->toBe($milestone->id)
        ->and($record->criteria_state[1])->toBe(['evidence_id' => null, 'visibility' => 'internal']);
});

test('the milestone assignment must live on the record\'s own baseline', function () {
    $setup = acceptanceSetup();
    ['manager' => $manager, 'checkout' => $checkout, 'checkoutRecord' => $record] = $setup;

    $this->actingAs($manager)
        ->patch(route('deliverables.update', $record), [
            'progress' => 10,
            'confidence' => 'medium',
            'milestone_item_id' => $checkout->id,
        ])
        ->assertInvalid(['milestone_item_id']);
});

test('members update execution, portfolio viewers stay read-only', function () {
    $setup = acceptanceSetup();
    ['organization' => $organization, 'checkoutRecord' => $record] = $setup;

    $member = User::factory()->role(UserRole::Member)->for($organization)->create();
    $viewer = User::factory()->role(UserRole::PortfolioViewer)->for($organization)->create();

    $this->actingAs($member)
        ->patch(route('deliverables.update', $record), ['progress' => 25, 'confidence' => 'medium'])
        ->assertRedirect();

    expect($record->refresh()->progress)->toBe(25);

    $this->actingAs($viewer)
        ->patch(route('deliverables.update', $record), ['progress' => 50, 'confidence' => 'medium'])
        ->assertForbidden();

    // Submitting for acceptance is governance — members cannot.
    $this->actingAs($member)
        ->post(route('deliverables.submit', $record), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertForbidden();
});

test('evidence is added and removed on the audit record', function () {
    $setup = acceptanceSetup();
    ['manager' => $manager, 'checkoutRecord' => $record] = $setup;

    $this->actingAs($manager)
        ->post(route('deliverables.evidence.store', $record), [
            'kind' => 'test_report',
            'label' => 'UAT round 2 results',
            'url' => 'https://example.test/uat-2',
            'visibility' => 'shared',
        ])
        ->assertRedirect(route('deliverables.show', $record));

    $evidence = $record->evidence()->sole();

    expect($evidence->kind)->toBe(EvidenceKind::TestReport)
        ->and(AuditLog::query()->where('action', 'deliverable.evidence_added')->where('subject_id', $record->id)->exists())->toBeTrue();

    // Link it to the first criterion, then remove it: the criterion falls
    // back to unevidenced rather than dangling.
    $record->update(['criteria_state' => [
        ['evidence_id' => $evidence->id, 'visibility' => 'shared'],
        ['evidence_id' => null, 'visibility' => 'shared'],
    ]]);

    $this->actingAs($manager)
        ->delete(route('deliverables.evidence.destroy', [$record, $evidence]))
        ->assertRedirect(route('deliverables.show', $record));

    expect($record->refresh()->evidence()->count())->toBe(0)
        ->and($record->criteria_state[0]['evidence_id'])->toBeNull()
        ->and(AuditLog::query()->where('action', 'deliverable.evidence_removed')->where('subject_id', $record->id)->exists())->toBeTrue();
});

test('submission requires evidence behind every criterion', function () {
    $setup = acceptanceSetup();
    ['manager' => $manager, 'checkoutRecord' => $record] = $setup;

    $this->actingAs($manager)
        ->post(route('deliverables.submit', $record), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertInvalid(['criteria']);

    expect($record->refresh()->status)->toBe(DeliverableStatus::InProgress);
});

test('submission freezes twin snapshots and notifies the approvers personally', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    $viewer = Stakeholder::factory()
        ->for($organization)
        ->for($engagement->customer)
        ->role(StakeholderRole::Viewer)
        ->create();

    linkCriterionEvidence($record, $manager);

    $this->actingAs($manager)
        ->post(route('deliverables.submit', $record), ['respond_by' => today()->addDays(14)->toDateString()])
        ->assertRedirect(route('deliverables.show', $record));

    $record->refresh();

    expect($record->status)->toBe(DeliverableStatus::AwaitingAcceptance)
        ->and($record->submitted_at)->not->toBeNull()
        ->and($record->respond_by?->toDateString())->toBe(today()->addDays(14)->toDateString())
        ->and($record->reviewSnapshot?->payload['kind'])->toBe('internal_review')
        ->and($record->customerSnapshot?->payload['kind'])->toBe('customer_review')
        ->and($record->reviewSnapshot?->verifyIntegrity())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'deliverable.submitted')->where('subject_id', $record->id)->exists())->toBeTrue();

    Notification::assertSentTo($approver, DeliverableSubmitted::class);
    Notification::assertNotSentTo($viewer, DeliverableSubmitted::class);
});

test('the customer snapshot carries shared evidence only and never cost, margin or rates', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'checkoutRecord' => $record] = $setup;

    $shared = linkCriterionEvidence($record, $manager);
    $record->evidence()->create([
        'organization_id' => $record->organization_id,
        'kind' => EvidenceKind::Document,
        'label' => 'Internal margin note',
        'url' => 'https://example.test/internal',
        'visibility' => RecordVisibility::Internal,
        'added_by' => $manager->id,
    ]);

    // The second criterion's evidence is marked internal: the criterion
    // itself stays visible (the customer signed it at baseline approval),
    // its evidence does not.
    $record->update(['criteria_state' => [
        ['evidence_id' => $shared->id, 'visibility' => 'shared'],
        ['evidence_id' => $shared->id, 'visibility' => 'internal'],
    ]]);

    $record->submitForAcceptance(today()->addDays(14), $manager);
    $record->refresh();

    $payload = $record->customerSnapshot?->payload;
    $json = mb_strtolower(json_encode($payload, JSON_THROW_ON_ERROR));

    expect($json)->not->toContain('cost')
        ->and($json)->not->toContain('margin')
        ->and($json)->not->toContain('rate')
        ->and($json)->not->toContain('allocation')
        ->and($json)->not->toContain('confidence')
        ->and($payload['value']['amount'])->toBe(1200000)
        ->and($payload['acceptance_criteria'])->toHaveCount(2)
        ->and($payload['acceptance_criteria'][0]['evidence']['label'])->toBe('Sprint 12 build')
        ->and($payload['acceptance_criteria'][1]['evidence'])->toBeNull()
        ->and($payload['evidence'])->toHaveCount(1);

    // The internal twin keeps the full picture: confidence, visibility
    // flags, the internal document and the work context.
    $internal = $record->reviewSnapshot?->payload;

    expect($internal['confidence'])->toBe('medium')
        ->and($internal['evidence'])->toHaveCount(2)
        ->and($internal['acceptance_criteria'][1]['evidence']['label'])->toBe('Sprint 12 build')
        ->and($internal['owner']['name'])->toBe('Delivery Owner');
});

test('a submitted deliverable is frozen until the customer decides', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    expect(fn () => $record->refresh()->update(['progress' => 99]))
        ->toThrow(LogicException::class, 'A submitted deliverable is frozen while it awaits the customer decision.');

    $this->actingAs($manager)
        ->patch(route('deliverables.update', $record), ['progress' => 99, 'confidence' => 'high'])
        ->assertInvalid(['progress']);

    $this->actingAs($manager)
        ->post(route('deliverables.evidence.store', $record), [
            'kind' => 'demo', 'label' => 'Late addition', 'visibility' => 'shared',
        ])
        ->assertInvalid(['label']);
});

test('acceptance records an immutable response, freezes the signed value and accrues to the rail', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    $response = $record->refresh()->recordResponse($approver, AcceptanceDecision::Accepted, 'Signed with pleasure.');

    $record->refresh();

    expect($record->status)->toBe(DeliverableStatus::Accepted)
        ->and($record->accepted_at)->not->toBeNull()
        ->and($record->accepted_value?->amount)->toBe(1200000)
        ->and($response->decision)->toBe(AcceptanceDecision::Accepted)
        ->and($response->snapshot_id)->toBe($record->customer_snapshot_id)
        ->and($response->stakeholder_name)->toBe('Anders Vik')
        ->and(fn () => $response->update(['comment' => 'Edited']))->toThrow(LogicException::class)
        ->and(AuditLog::query()->where('action', 'deliverable.accepted')->where('subject_id', $record->id)->exists())->toBeTrue();

    // Accepted always means signed — and stays signed.
    expect(fn () => $record->update(['progress' => 10]))
        ->toThrow(LogicException::class, 'An accepted deliverable is immutable — the signed acceptance is on record.')
        ->and(fn () => $record->submitForAcceptance(today()->addDays(7), $manager))
        ->toThrow(LogicException::class)
        ->and(fn () => $record->recordResponse($approver, AcceptanceDecision::Rejected))
        ->toThrow(ValidationException::class);

    $position = $engagement->positionSummary(withCommercials: true);

    expect($position['accepted']['value']['amount'])->toBe(1200000)
        ->and($position['accepted']['count'])->toBe(1)
        ->and($position['accepted']['total'])->toBe(2);
});

test('rejection reopens the record for rework and resubmission', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);
    $record->refresh()->recordResponse($approver, AcceptanceDecision::Rejected, 'The refund flow fails.');

    $record->refresh();

    expect($record->status)->toBe(DeliverableStatus::Rejected)
        ->and($record->decided_at)->not->toBeNull()
        ->and($record->accepted_value)->toBeNull();

    // Rework and resubmit with fresh snapshots; the first pair stays on record.
    $record->update(['progress' => 95]);
    $record->submitForAcceptance(today()->addDays(7), $manager);

    expect($record->refresh()->status)->toBe(DeliverableStatus::AwaitingAcceptance)
        ->and(Snapshot::query()->where('subject_id', $record->id)->count())->toBe(4);

    $record->recordResponse($approver, AcceptanceDecision::Accepted);

    expect($record->refresh()->status)->toBe(DeliverableStatus::Accepted)
        ->and($record->responses)->toHaveCount(2);
});

test('a clarification request reopens the record without a verdict', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);
    $record->refresh()->recordResponse($approver, AcceptanceDecision::ClarificationRequested, 'Which browsers were tested?');

    $record->refresh();

    expect($record->status)->toBe(DeliverableStatus::InProgress)
        ->and($record->decided_at)->toBeNull()
        ->and($record->responses)->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'deliverable.clarification_requested')->where('subject_id', $record->id)->exists())->toBeTrue();
});

test('stakeholders without approval rights cannot respond', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    $viewer = Stakeholder::factory()
        ->for($organization)
        ->for($engagement->customer)
        ->role(StakeholderRole::Viewer)
        ->create();

    expect(fn () => $record->refresh()->recordResponse($viewer, AcceptanceDecision::Accepted))
        ->toThrow(ValidationException::class);

    $this->get(URL::signedRoute('portal.deliverables.show', [
        'deliverable' => $record->id,
        'stakeholder' => $viewer->id,
    ]))->assertForbidden();
});

test('the portal shows the frozen record on a personally signed link', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    $signed = URL::signedRoute('portal.deliverables.show', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $record->refresh()->customer_snapshot_id,
    ]);

    $this->get($signed)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/deliverable')
            ->where('review.deliverable.title', 'Checkout flow')
            ->where('review.value.amount', 1200000)
            ->where('superseded', false)
            ->where('canRespond', true)
            ->where('stakeholder.name', 'Anders Vik'));

    // The same link without its signature proves nothing.
    $this->get(route('portal.deliverables.show', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $record->customer_snapshot_id,
    ]))->assertForbidden();
});

test('the portal records the decision immutably against the frozen snapshot', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    $respondUrl = URL::signedRoute('portal.deliverables.respond', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $record->refresh()->customer_snapshot_id,
    ]);

    $this->post($respondUrl, ['decision' => 'accepted', 'comment' => 'Signed off in the portal.'])
        ->assertRedirect();

    $record->refresh();

    expect($record->status)->toBe(DeliverableStatus::Accepted)
        ->and($record->responses->sole()->comment)->toBe('Signed off in the portal.')
        ->and($record->responses->sole()->stakeholder_name)->toBe('Anders Vik');
});

test('an approved change request carries records, mappings and history onto the new version', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'milestone' => $milestone, 'approver' => $approver, 'developer' => $developer, 'checkout' => $checkout, 'checkoutRecord' => $record] = $setup;

    // Execution context on v1: the record sits on the go-live milestone, a
    // work item is mapped to the checkout deliverable, and the customer has
    // already signed the checkout acceptance at €12,000.
    $record->update(['milestone_item_id' => $milestone->id]);
    $workItem = WorkItem::factory()->for($organization)->for($engagement)->create(['title' => 'Implement wallet payments']);
    $workItem->linkTo($checkout, $manager);
    signOffDeliverable($record, $manager, $approver);

    $changeRequest = $engagement->draftChangeRequest([
        'title' => 'Supplier portal module',
        'what' => 'A supplier-facing portal was requested in the last steering call.',
        'origin' => ChangeRequestOrigin::SteeringCall,
    ], $manager);
    $changeRequest->startAssessment($manager);
    $changeRequest->allocations()->create([
        'organization_id' => $organization->id,
        'rate_card_role_id' => $developer->id,
        'days' => '3',
    ]);
    $changeRequest->update(['impact_milestone_id' => $milestone->id, 'impact_days' => 10]);
    $changeRequest->moveToProposal($manager);
    $changeRequest->update(['customer_price' => Money::fromCents(400000)]);
    $changeRequest->submitToCustomer(today()->addDays(7), $manager);
    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);

    $minted = $changeRequest->refresh()->mintedBaseline;
    $newCheckout = $minted->items->firstWhere('title', 'Checkout flow');
    $newMilestone = $minted->items->firstWhere('title', 'Go-live');
    $record->refresh();

    // The accepted record follows its item onto v2 — signed value intact —
    // its version trail grows, and the work mapping moves with it.
    expect($record->baseline_item_id)->toBe($newCheckout->id)
        ->and($record->milestone_item_id)->toBe($newMilestone->id)
        ->and($record->status)->toBe(DeliverableStatus::Accepted)
        ->and($record->accepted_value?->amount)->toBe(1200000)
        ->and($record->versions()->count())->toBe(2)
        ->and($workItem->refresh()->link->baseline_item_id)->toBe($newCheckout->id);

    // The approved change itself becomes a deliverable with its own fresh record.
    $appended = Deliverable::query()
        ->where('engagement_id', $engagement->id)
        ->get()
        ->first(fn (Deliverable $deliverable): bool => $deliverable->baselineItem->title === 'Supplier portal module');

    expect(Deliverable::query()->where('engagement_id', $engagement->id)->count())->toBe(3)
        ->and($appended)->not->toBeNull()
        ->and($appended->status)->toBe(DeliverableStatus::InProgress)
        ->and($appended->criteria_state)->toBe([]);
});

test('milestone acceptance packs assemble from signed deliverables', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'milestone' => $milestone, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    $checkoutRecord->update(['milestone_item_id' => $milestone->id]);
    $reportingRecord->update(['milestone_item_id' => $milestone->id]);

    signOffDeliverable($checkoutRecord, $manager, $approver);

    $this->actingAs($manager)
        ->get(route('engagements.milestones.acceptance-pack', [$engagement, $milestone]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('milestones/acceptance-pack')
            ->where('milestone.title', 'Go-live')
            ->where('totals.count', 2)
            ->where('totals.acceptedCount', 1)
            ->where('totals.acceptedValue.amount', 1200000)
            ->where('complete', false)
            ->where('deliverables.0.acceptedBy', 'Anders Vik'));

    signOffDeliverable($reportingRecord, $manager, $approver);

    $this->actingAs($manager)
        ->get(route('engagements.milestones.acceptance-pack', [$engagement, $milestone]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totals.acceptedCount', 2)
            ->where('totals.acceptedValue.amount', 2000000)
            ->where('complete', true));

    // A deliverable item is no milestone — the pack route refuses it.
    $this->actingAs($manager)
        ->get(route('engagements.milestones.acceptance-pack', [$engagement, $setup['checkout']]))
        ->assertNotFound();
});

test('final acceptance requires every deliverable signed off', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord] = $setup;

    signOffDeliverable($checkoutRecord, $manager, $approver);

    $this->actingAs($manager)
        ->post(route('engagements.final-acceptance.store', $engagement), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertInvalid(['respond_by']);

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Active);
});

test('final acceptance freezes the signed record and notifies the approvers', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    signOffDeliverable($checkoutRecord, $manager, $approver);
    signOffDeliverable($reportingRecord, $manager, $approver);

    $this->actingAs($manager)
        ->post(route('engagements.final-acceptance.store', $engagement), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertRedirect(route('engagements.show', $engagement));

    $engagement->refresh();
    $finalAcceptance = $engagement->currentFinalAcceptance();

    expect($engagement->status)->toBe(EngagementStatus::AwaitingFinalAcceptance)
        ->and($finalAcceptance->status)->toBe(FinalAcceptanceStatus::AwaitingResponse)
        ->and($finalAcceptance->customerSnapshot?->payload['kind'])->toBe('customer_review')
        ->and($finalAcceptance->customerSnapshot?->payload['accepted_value']['amount'])->toBe(2000000)
        ->and($finalAcceptance->customerSnapshot?->payload['deliverables'])->toHaveCount(2)
        ->and($finalAcceptance->customerSnapshot?->payload['deliverables'][0]['accepted_by'])->toBe('Anders Vik')
        ->and(AuditLog::query()->where('action', 'final_acceptance.submitted')->where('subject_id', $finalAcceptance->id)->exists())->toBeTrue();

    Notification::assertSentTo($setup['approver'], FinalAcceptanceSubmitted::class);
});

test('the customer\'s signature completes the engagement', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    signOffDeliverable($checkoutRecord, $manager, $approver);
    signOffDeliverable($reportingRecord, $manager, $approver);
    $engagement->submitForFinalAcceptance(today()->addDays(7), $manager);

    // Completed cannot be reached by hand while the signature is missing.
    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'completed'])
        ->assertSessionHasErrors('status');

    $finalAcceptance = $engagement->currentFinalAcceptance();

    $respondUrl = URL::signedRoute('portal.final-acceptances.respond', [
        'finalAcceptance' => $finalAcceptance->id,
        'stakeholder' => $approver->id,
    ]);

    $this->post($respondUrl, ['decision' => 'accepted', 'comment' => 'A pleasure working together.'])
        ->assertRedirect();

    $engagement->refresh();
    $finalAcceptance->refresh();

    expect($engagement->status)->toBe(EngagementStatus::Completed)
        ->and($finalAcceptance->status)->toBe(FinalAcceptanceStatus::Accepted)
        ->and($finalAcceptance->stakeholder_name)->toBe('Anders Vik')
        ->and($finalAcceptance->comment)->toBe('A pleasure working together.')
        ->and(fn () => $finalAcceptance->forceFill(['comment' => 'Edited'])->save())
        ->toThrow(LogicException::class, 'A closed final acceptance is immutable — a resubmission is a new record.');
});

test('a rejected final acceptance returns the engagement to active for rework', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    signOffDeliverable($checkoutRecord, $manager, $approver);
    signOffDeliverable($reportingRecord, $manager, $approver);
    $engagement->submitForFinalAcceptance(today()->addDays(7), $manager);

    $engagement->currentFinalAcceptance()->recordResponse($approver, AcceptanceDecision::Rejected, 'The handover documents are missing.');

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Active)
        ->and($engagement->currentFinalAcceptance()->status)->toBe(FinalAcceptanceStatus::Rejected);

    // A fresh submission is a new record, and its acceptance completes.
    $second = $engagement->submitForFinalAcceptance(today()->addDays(7), $manager);
    $second->recordResponse($approver, AcceptanceDecision::Accepted);

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Completed)
        ->and($engagement->finalAcceptances()->count())->toBe(2);
});

test('moving back to active by hand withdraws the open final acceptance', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    signOffDeliverable($checkoutRecord, $manager, $approver);
    signOffDeliverable($reportingRecord, $manager, $approver);
    $engagement->submitForFinalAcceptance(today()->addDays(7), $manager);

    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'active'])
        ->assertRedirect(route('engagements.show', $engagement));

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Active)
        ->and($engagement->currentFinalAcceptance()->status)->toBe(FinalAcceptanceStatus::Withdrawn)
        ->and(AuditLog::query()->where('action', 'final_acceptance.withdrawn')->exists())->toBeTrue();
});

test('lifecycle shortcuts around the acceptance gate are refused', function () {
    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement] = $setup;

    // No open request: awaiting final acceptance is reached by submitting,
    // not by the transition button.
    $this->actingAs($manager)
        ->post(route('engagements.transition', $engagement), ['status' => 'awaiting_final_acceptance'])
        ->assertSessionHasErrors('status');

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Active);

    // And the model refuses completion without a signature outright.
    $engagement->forceFill(['status' => EngagementStatus::AwaitingFinalAcceptance])->save();

    expect(fn () => $engagement->transitionTo(EngagementStatus::Completed))
        ->toThrow(LogicException::class, "Completion requires the customer's signed final acceptance.");
});

test('the deliverables index lists records grouped for the milestone view', function () {
    $setup = acceptanceSetup();
    ['manager' => $manager, 'engagement' => $engagement] = $setup;

    $this->actingAs($manager)
        ->get(route('engagements.deliverables.index', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/deliverables')
            ->has('deliverables', 2)
            ->where('deliverables.0.title', 'Checkout flow')
            ->where('deliverables.0.value.amount', 1200000)
            ->where('deliverables.0.criteriaCount', 2)
            ->has('milestones', 1)
            ->where('accepted.count', 0)
            ->where('accepted.total', 2)
            ->where('baselineVersion', 1));
});

test('the index self-heals records for baselines approved outside the flow', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()->for($manager->organization)->status(EngagementStatus::Active)->create();
    $baseline = Baseline::factory()
        ->for($manager->organization)
        ->for($engagement)
        ->create();
    BaselineItem::factory()->for($manager->organization)->for($baseline)->completeDeliverable()->create();
    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    expect(Deliverable::query()->count())->toBe(0);

    $this->actingAs($manager)
        ->get(route('engagements.deliverables.index', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('deliverables', 1));

    expect(Deliverable::query()->count())->toBe(1);
});

test('the deliverable record shows its full history and context', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);
    $record->refresh()->recordResponse($approver, AcceptanceDecision::Accepted);

    $this->actingAs($manager)
        ->get(route('deliverables.show', $record))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('deliverables/show')
            ->where('deliverable.title', 'Checkout flow')
            ->where('deliverable.baselineVersion', 1)
            ->where('deliverable.status', 'accepted')
            ->where('deliverable.acceptedValue.amount', 1200000)
            ->has('criteria', 2)
            ->has('evidence', 1)
            ->has('versions', 1)
            ->has('responses', 1)
            ->where('can.update', false)
            ->where('can.submit', false));
});

test('the frozen record carries the deadline the customer is asked to meet', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    $record->refresh();

    expect($record->customerSnapshot?->payload['respond_by'])->toBe(today()->addDays(14)->toDateString())
        ->and($record->reviewSnapshot?->payload['respond_by'])->toBe(today()->addDays(14)->toDateString());
});

test('a stale portal link cannot sign a revised deliverable', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);

    $staleSnapshotId = $record->refresh()->customer_snapshot_id;
    $staleRespond = URL::signedRoute('portal.deliverables.respond', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $staleSnapshotId,
    ]);

    // A clarification round reworks the record and freezes fresh snapshots.
    $record->recordResponse($approver, AcceptanceDecision::ClarificationRequested, 'Which browsers were tested?');
    $record->refresh()->update(['progress' => 95]);
    $record->submitForAcceptance(today()->addDays(7), $manager);

    // The old tab still shows 0% progress — its link cannot sign 95%.
    $this->post($staleRespond, ['decision' => 'accepted'])->assertInvalid(['decision']);

    expect($record->refresh()->status)->toBe(DeliverableStatus::AwaitingAcceptance)
        ->and($record->responses)->toHaveCount(1);

    // The superseded link keeps showing what it always showed, read-only.
    $this->get(URL::signedRoute('portal.deliverables.show', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $staleSnapshotId,
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('review.progress', 0)
            ->where('superseded', true)
            ->where('canRespond', false));

    // The fresh link signs as usual, recorded against the fresh snapshot.
    $this->post(URL::signedRoute('portal.deliverables.respond', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $record->customer_snapshot_id,
    ]), ['decision' => 'accepted'])->assertRedirect();

    $record->refresh();

    expect($record->status)->toBe(DeliverableStatus::Accepted)
        ->and($record->responses->firstWhere('decision', AcceptanceDecision::Accepted)?->snapshot_id)
        ->toBe($record->customer_snapshot_id);

    // Each page shows only the decisions made on its own record: the
    // signature on 95% never bleeds into the superseded 0% page.
    $this->get(URL::signedRoute('portal.deliverables.show', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $staleSnapshotId,
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('superseded', true)
            ->has('responses', 1)
            ->where('responses.0.decision', 'clarification_requested'));

    $this->get(URL::signedRoute('portal.deliverables.show', [
        'deliverable' => $record->id,
        'stakeholder' => $approver->id,
        'snapshot' => $record->customer_snapshot_id,
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('superseded', false)
            ->has('responses', 1)
            ->where('responses.0.decision', 'accepted'));
});

test('a review link naming a snapshot of another record is refused', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record, 'reportingRecord' => $other] = $setup;

    linkCriterionEvidence($record, $manager);
    $record->submitForAcceptance(today()->addDays(14), $manager);
    linkCriterionEvidence($other, $manager);
    $other->submitForAcceptance(today()->addDays(14), $manager);

    // The snapshot lookup is scoped to its own deliverable, and the internal
    // twin is never a customer-facing record.
    foreach ([$other->refresh()->customer_snapshot_id, $record->refresh()->review_snapshot_id] as $foreignSnapshotId) {
        $this->get(URL::signedRoute('portal.deliverables.show', [
            'deliverable' => $record->id,
            'stakeholder' => $approver->id,
            'snapshot' => $foreignSnapshotId,
        ]))->assertNotFound();
    }
});

test('submission needs someone who can sign, and can be withdrawn when nobody can', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'approver' => $approver, 'checkoutRecord' => $record] = $setup;

    linkCriterionEvidence($record, $manager);
    $approver->update(['role' => StakeholderRole::Viewer]);

    // Freezing the record against a decision nobody can make is a dead end.
    $this->actingAs($manager)
        ->post(route('deliverables.submit', $record), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertInvalid(['respond_by']);

    expect($record->refresh()->status)->toBe(DeliverableStatus::InProgress);

    // Submitted while an approver existed, then left without one: the record
    // reopens instead of waiting forever.
    $approver->update(['role' => StakeholderRole::Approver]);
    $record->submitForAcceptance(today()->addDays(7), $manager);
    $approver->update(['role' => StakeholderRole::Viewer]);

    $this->actingAs($manager)
        ->delete(route('deliverables.submit.withdraw', $record))
        ->assertRedirect(route('deliverables.show', $record));

    expect($record->refresh()->status)->toBe(DeliverableStatus::InProgress)
        ->and($record->customer_snapshot_id)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'deliverable.submission_withdrawn')->where('subject_id', $record->id)->exists())->toBeTrue();

    // Only a submitted record can be withdrawn.
    $this->actingAs($manager)
        ->delete(route('deliverables.submit.withdraw', $record))
        ->assertInvalid(['status']);
});

test('a change-request deliverable cannot be signed off without evidence the customer can see', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'approver' => $approver, 'developer' => $developer] = $setup;

    $changeRequest = $engagement->draftChangeRequest([
        'title' => 'Supplier portal module',
        'what' => 'A supplier-facing portal was requested in the last steering call.',
        'origin' => ChangeRequestOrigin::SteeringCall,
    ], $manager);
    $changeRequest->startAssessment($manager);
    $changeRequest->allocations()->create([
        'organization_id' => $organization->id,
        'rate_card_role_id' => $developer->id,
        'days' => '3',
    ]);
    $changeRequest->moveToProposal($manager);
    $changeRequest->update(['customer_price' => Money::fromCents(400000)]);
    $changeRequest->submitToCustomer(today()->addDays(7), $manager);
    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);

    $appended = Deliverable::query()
        ->where('engagement_id', $engagement->id)
        ->get()
        ->first(fn (Deliverable $deliverable): bool => $deliverable->baselineItem->title === 'Supplier portal module');

    // A minted deliverable carries no acceptance criteria, so the criterion
    // gate has nothing to catch — an empty review would go to the customer.
    expect($appended->criteria())->toBe([]);

    $this->actingAs($manager)
        ->post(route('deliverables.submit', $appended), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertInvalid(['criteria']);

    // Internal evidence is still nothing the customer can see.
    $appended->evidence()->create([
        'organization_id' => $organization->id,
        'kind' => EvidenceKind::Document,
        'label' => 'Internal handover note',
        'visibility' => RecordVisibility::Internal,
        'added_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post(route('deliverables.submit', $appended), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertInvalid(['criteria']);

    $appended->evidence()->create([
        'organization_id' => $organization->id,
        'kind' => EvidenceKind::Demo,
        'label' => 'Supplier portal walkthrough',
        'visibility' => RecordVisibility::Shared,
        'added_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->post(route('deliverables.submit', $appended), ['respond_by' => today()->addDays(7)->toDateString()])
        ->assertRedirect(route('deliverables.show', $appended));

    expect($appended->refresh()->status)->toBe(DeliverableStatus::AwaitingAcceptance)
        ->and($appended->customerSnapshot?->payload['evidence'])->toHaveCount(1);
});

test('scope approved after the freeze reopens the engagement and blocks completion', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'approver' => $approver, 'developer' => $developer, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    // A change request is already with the customer when the engagement goes
    // up for final acceptance.
    $changeRequest = $engagement->draftChangeRequest([
        'title' => 'Supplier portal module',
        'what' => 'A supplier-facing portal was requested in the last steering call.',
        'origin' => ChangeRequestOrigin::SteeringCall,
    ], $manager);
    $changeRequest->startAssessment($manager);
    $changeRequest->allocations()->create([
        'organization_id' => $organization->id,
        'rate_card_role_id' => $developer->id,
        'days' => '3',
    ]);
    $changeRequest->moveToProposal($manager);
    $changeRequest->update(['customer_price' => Money::fromCents(400000)]);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    signOffDeliverable($checkoutRecord, $manager, $approver);
    signOffDeliverable($reportingRecord, $manager, $approver);
    $finalAcceptance = $engagement->submitForFinalAcceptance(today()->addDays(7), $manager);

    // Approving it now adds a deliverable the frozen record never listed.
    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Active)
        ->and($finalAcceptance->refresh()->status)->toBe(FinalAcceptanceStatus::Withdrawn)
        ->and($engagement->deliverables()->where('status', DeliverableStatus::Accepted)->count())->toBe(2)
        ->and($engagement->deliverables()->count())->toBe(3);

    // The withdrawn record cannot complete the engagement...
    expect(fn () => $finalAcceptance->recordResponse($approver, AcceptanceDecision::Accepted))
        ->toThrow(ValidationException::class);

    // ...and a fresh one waits until the new scope is signed too.
    expect(fn () => $engagement->submitForFinalAcceptance(today()->addDays(7), $manager))
        ->toThrow(ValidationException::class);

    expect($engagement->refresh()->status)->toBe(EngagementStatus::Active);
});

test('final acceptance refuses to close over a deliverable that is not signed', function () {
    Notification::fake();

    $setup = acceptanceSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'approver' => $approver, 'checkoutRecord' => $checkoutRecord, 'reportingRecord' => $reportingRecord] = $setup;

    signOffDeliverable($checkoutRecord, $manager, $approver);
    signOffDeliverable($reportingRecord, $manager, $approver);
    $finalAcceptance = $engagement->submitForFinalAcceptance(today()->addDays(7), $manager);

    // An unsigned deliverable appearing by any other route still stops the
    // gate: the signed set must be the whole engagement.
    $laterBaseline = Baseline::factory()->for($organization)->for($engagement)->create(['version' => 2]);
    $laterItem = BaselineItem::factory()->for($organization)->for($laterBaseline)->completeDeliverable()->create();
    Deliverable::factory()->for($organization)->for($engagement)->create(['baseline_item_id' => $laterItem->id]);

    expect(fn () => $finalAcceptance->recordResponse($approver, AcceptanceDecision::Accepted))
        ->toThrow(ValidationException::class);

    expect($engagement->refresh()->status)->toBe(EngagementStatus::AwaitingFinalAcceptance)
        ->and($finalAcceptance->refresh()->status)->toBe(FinalAcceptanceStatus::AwaitingResponse);
});

test('deliverable records of other organizations are hidden', function () {
    $setup = acceptanceSetup();
    $foreignManager = User::factory()->role(UserRole::DeliveryManager)->create();

    $this->actingAs($foreignManager)
        ->get(route('deliverables.show', $setup['checkoutRecord']))
        ->assertNotFound();
});
