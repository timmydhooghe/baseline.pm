<?php

use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestDecision;
use App\Enums\ChangeRequestOrigin;
use App\Enums\ChangeRequestStatus;
use App\Enums\EngagementStatus;
use App\Enums\EstimateUnit;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Enums\WorkItemState;
use App\Enums\WorkItemTriageStatus;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\ChangeRequestReminder;
use App\Notifications\ChangeRequestSubmitted;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * A delivery manager on an active engagement executing against approved
 * baseline v1: two valued deliverables summing to the €20,000 contract, a
 * dated go-live milestone, a two-role rate card (€450/€780 and €400/€700
 * cost/sell) and a draft change request raised from a steering call. All
 * free text is fixed so leakage assertions can scan whole payloads.
 *
 * @return array<string, mixed>
 */
function changeControlSetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $organization = $manager->organization;

    $version = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
        ['name' => 'Designer', 'cost_per_day' => Money::fromCents(40000), 'sell_per_day' => Money::fromCents(70000)],
    ]);
    $developer = $version->roles->firstWhere('name', 'Developer');
    $designer = $version->roles->firstWhere('name', 'Designer');

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
        'title' => 'Checkout flow', 'description' => 'The full purchase funnel.', 'value' => Money::fromCents(1200000), 'position' => 1, 'owner_id' => $owner->id,
    ]);
    $reporting = BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Reporting pack', 'description' => 'Weekly figures for finance.', 'value' => Money::fromCents(800000), 'position' => 2, 'owner_id' => $owner->id,
    ]);
    $milestone = BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live', 'description' => null, 'position' => 1, 'baseline_date' => today()->addDays(30),
    ]);

    foreach ([[$checkout->id, '10'], [$reporting->id, '5'], [null, '3']] as [$itemId, $days]) {
        BaselineAllocation::factory()->for($organization)->for($baseline)->create([
            'baseline_item_id' => $itemId,
            'rate_card_role_id' => $developer->id,
            'days' => $days,
        ]);
    }

    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    $changeRequest = $engagement->draftChangeRequest([
        'title' => 'Supplier portal module',
        'what' => 'A supplier-facing portal was requested in the last steering call.',
        'why' => 'Suppliers currently email spreadsheets, which slows the finance team down.',
        'origin' => ChangeRequestOrigin::SteeringCall,
        'estimated_days' => 4,
    ], $manager);

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
        'changeRequest' => $changeRequest,
        'developer' => $developer,
        'designer' => $designer,
        'checkout' => $checkout,
        'milestone' => $milestone,
        'approver' => $approver,
    ];
}

/**
 * Drive the draft into a priced customer proposal: developer 3d + designer
 * 2d (derived cost €2,150, suggested price €3,740), the checkout deliverable
 * affected, go-live pushed 10 days, price set to €4,000.
 */
function priceProposal(array $setup): ChangeRequest
{
    $changeRequest = $setup['changeRequest'];

    $changeRequest->startAssessment($setup['manager']);

    foreach ([[$setup['developer']->id, '3'], [$setup['designer']->id, '2']] as [$roleId, $days]) {
        $changeRequest->allocations()->create([
            'organization_id' => $changeRequest->organization_id,
            'rate_card_role_id' => $roleId,
            'days' => $days,
        ]);
    }

    $changeRequest->affectedItems()->sync([$setup['checkout']->id]);
    $changeRequest->update([
        'impact_milestone_id' => $setup['milestone']->id,
        'impact_days' => 10,
        'scope_added' => 'A supplier portal with document upload.',
        'scope_removed' => null,
        'alternatives' => 'Defer the reporting pack by one sprint instead.',
    ]);

    $changeRequest->moveToProposal($setup['manager']);
    $changeRequest->update(['customer_price' => Money::fromCents(400000)]);

    return $changeRequest->refresh();
}

test('a manager raises a change request by hand', function () {
    ['manager' => $manager, 'engagement' => $engagement] = changeControlSetup();

    $this->actingAs($manager)
        ->post(route('engagements.change-requests.store', $engagement), [
            'title' => 'Second warehouse',
            'what' => 'Finance asked for a second warehouse in the rollout.',
            'origin' => 'email',
            'estimated_days' => 2.5,
        ])
        ->assertRedirect();

    $changeRequest = $engagement->changeRequests()->where('title', 'Second warehouse')->sole();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::Draft)
        ->and($changeRequest->origin)->toBe(ChangeRequestOrigin::Email)
        ->and($changeRequest->estimated_days)->toBe(2.5)
        ->and(AuditLog::query()->where('action', 'change_request.drafted')->where('subject_id', $changeRequest->id)->exists())->toBeTrue();
});

test('members cannot raise or manage change requests', function () {
    ['organization' => $organization, 'engagement' => $engagement, 'changeRequest' => $changeRequest] = changeControlSetup();
    $member = User::factory()->role(UserRole::Member)->for($organization)->create();

    $this->actingAs($member)
        ->post(route('engagements.change-requests.store', $engagement), [
            'title' => 'x', 'what' => 'y', 'origin' => 'email',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('change-requests.transition', $changeRequest), ['status' => 'under_assessment'])
        ->assertForbidden();
});

test('starting the assessment pins the approved baseline rate card version', function () {
    ['manager' => $manager, 'baseline' => $baseline, 'changeRequest' => $changeRequest] = changeControlSetup();

    $this->actingAs($manager)
        ->post(route('change-requests.transition', $changeRequest), ['status' => 'under_assessment'])
        ->assertRedirect(route('change-requests.show', $changeRequest));

    $changeRequest->refresh();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::UnderAssessment)
        ->and($changeRequest->rate_card_version_id)->toBe($baseline->rate_card_version_id)
        ->and(AuditLog::query()->where('action', 'change_request.assessment_started')->where('subject_id', $changeRequest->id)->exists())->toBeTrue();
});

test('assessment requires an approved baseline to price against', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $changeRequest = ChangeRequest::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('change-requests.transition', $changeRequest), ['status' => 'under_assessment'])
        ->assertInvalid(['status']);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::Draft);
});

test('the assessment stores the role mix, affected items and structured schedule impact', function () {
    $setup = changeControlSetup();
    ['manager' => $manager, 'changeRequest' => $changeRequest, 'developer' => $developer, 'designer' => $designer, 'checkout' => $checkout, 'milestone' => $milestone] = $setup;

    $changeRequest->startAssessment($manager);

    $this->actingAs($manager)
        ->put(route('change-requests.assessment.update', $changeRequest), [
            'allocations' => [
                ['rate_card_role_id' => $developer->id, 'days' => 3],
                ['rate_card_role_id' => $designer->id, 'days' => 2],
            ],
            'affected_items' => [$checkout->id],
            'impact_milestone_id' => $milestone->id,
            'impact_days' => 10,
            'scope_added' => 'A supplier portal with document upload.',
            'alternatives' => 'Defer the reporting pack by one sprint instead.',
        ])
        ->assertRedirect(route('change-requests.show', $changeRequest));

    $changeRequest->refresh()->load(['allocations.role', 'affectedItems']);

    // Developer 3d × €450 + designer 2d × €400 = €2,150 derived cost;
    // the same mix at sell rates suggests €3,740.
    expect($changeRequest->allocations)->toHaveCount(2)
        ->and($changeRequest->cost()->amount)->toBe(215000)
        ->and($changeRequest->suggestedPrice()?->amount)->toBe(374000)
        ->and($changeRequest->affectedItems->pluck('id')->all())->toBe([$checkout->id])
        ->and($changeRequest->impact_milestone_id)->toBe($milestone->id)
        ->and($changeRequest->impact_days)->toBe(10);
});

test('roles outside the pinned rate card version are refused', function () {
    $setup = changeControlSetup();
    ['manager' => $manager, 'organization' => $organization, 'changeRequest' => $changeRequest] = $setup;

    $changeRequest->startAssessment($manager);

    $laterVersion = $organization->publishRateCardVersion([
        ['name' => 'Architect', 'cost_per_day' => Money::fromCents(90000), 'sell_per_day' => Money::fromCents(150000)],
    ]);

    $this->actingAs($manager)
        ->put(route('change-requests.assessment.update', $changeRequest), [
            'allocations' => [
                ['rate_card_role_id' => $laterVersion->roles->sole()->id, 'days' => 1],
            ],
            'affected_items' => [],
        ])
        ->assertInvalid(['allocations.0.rate_card_role_id']);
});

test('schedule impact must reference a milestone on the approved baseline', function () {
    $setup = changeControlSetup();
    ['manager' => $manager, 'changeRequest' => $changeRequest, 'checkout' => $checkout] = $setup;

    $changeRequest->startAssessment($manager);

    $this->actingAs($manager)
        ->put(route('change-requests.assessment.update', $changeRequest), [
            'allocations' => [],
            'affected_items' => [],
            'impact_milestone_id' => $checkout->id,
            'impact_days' => 5,
        ])
        ->assertInvalid(['impact_milestone_id']);
});

test('moving to the customer proposal requires an assessed role mix', function () {
    ['manager' => $manager, 'changeRequest' => $changeRequest] = changeControlSetup();

    $changeRequest->startAssessment($manager);

    $this->actingAs($manager)
        ->post(route('change-requests.transition', $changeRequest), ['status' => 'customer_proposal'])
        ->assertInvalid(['status']);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::UnderAssessment);
});

test('the customer price is a deliberate number beside a derived suggestion and margin', function () {
    $setup = changeControlSetup();
    $changeRequest = priceProposal($setup);

    $this->actingAs($setup['manager'])
        ->put(route('change-requests.proposal.update', $changeRequest), ['customer_price' => 4500])
        ->assertRedirect(route('change-requests.show', $changeRequest));

    $changeRequest->refresh()->load('allocations.role');

    expect($changeRequest->customer_price?->amount)->toBe(450000)
        ->and($changeRequest->margin()?->amount)->toBe(235000)
        ->and($changeRequest->marginPercent())->toBe(52.2);
});

test('submission freezes twin snapshots and notifies the approvers personally', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'organization' => $organization, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);

    $viewer = Stakeholder::factory()
        ->for($organization)
        ->for($setup['engagement']->customer)
        ->role(StakeholderRole::Viewer)
        ->create();

    $this->actingAs($manager)
        ->post(route('change-requests.submit', $changeRequest), ['respond_by' => today()->addDays(14)->toDateString()])
        ->assertRedirect(route('change-requests.show', $changeRequest));

    $changeRequest->refresh();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::AwaitingApproval)
        ->and($changeRequest->submitted_at)->not->toBeNull()
        ->and($changeRequest->respond_by?->toDateString())->toBe(today()->addDays(14)->toDateString())
        ->and($changeRequest->reviewSnapshot?->payload['kind'])->toBe('internal_review')
        ->and($changeRequest->customerSnapshot?->payload['kind'])->toBe('customer_review')
        ->and($changeRequest->reviewSnapshot?->verifyIntegrity())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'change_request.submitted')->where('subject_id', $changeRequest->id)->exists())->toBeTrue();

    Notification::assertSentTo($approver, ChangeRequestSubmitted::class);
    Notification::assertNotSentTo($viewer, ChangeRequestSubmitted::class);
});

test('submission requires a customer price', function () {
    $setup = changeControlSetup();
    ['manager' => $manager, 'changeRequest' => $changeRequest] = $setup;

    $changeRequest->startAssessment($manager);
    $changeRequest->allocations()->create([
        'organization_id' => $changeRequest->organization_id,
        'rate_card_role_id' => $setup['developer']->id,
        'days' => '3',
    ]);
    $changeRequest->moveToProposal($manager);

    $this->actingAs($manager)
        ->post(route('change-requests.submit', $changeRequest), ['respond_by' => today()->addDays(14)->toDateString()])
        ->assertInvalid(['customer_price']);
});

test('the internal snapshot locks the derived cost, suggestion and margin', function () {
    Notification::fake();

    $setup = changeControlSetup();
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $setup['manager']);

    $assessment = $changeRequest->refresh()->reviewSnapshot?->payload['assessment'];

    expect($assessment['rate_card_version'])->toBe(1)
        ->and($assessment['cost']['amount'])->toBe(215000)
        ->and($assessment['suggested_price']['amount'])->toBe(374000)
        ->and($assessment['margin']['amount'])->toBe(185000)
        ->and($assessment['margin_percent'])->toBe(46.3)
        ->and($assessment['allocations'])->toHaveCount(2);
});

test('the customer snapshot never contains cost, rate or margin data', function () {
    Notification::fake();

    $setup = changeControlSetup();
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $setup['manager']);

    $payload = $changeRequest->refresh()->customerSnapshot?->payload;
    $json = mb_strtolower(json_encode($payload, JSON_THROW_ON_ERROR));

    expect($json)->not->toContain('cost')
        ->and($json)->not->toContain('margin')
        ->and($json)->not->toContain('rate')
        ->and($json)->not->toContain('allocation')
        ->and($payload['price']['amount'])->toBe(400000)
        ->and($payload['schedule_impact']['days'])->toBe(10)
        ->and($payload['schedule_impact']['projected_date'])->toBe(today()->addDays(40)->toDateString())
        ->and($payload['affected_items'][0]['title'])->toBe('Checkout flow');
});

test('a submitted proposal is frozen until the customer decides', function () {
    Notification::fake();

    $setup = changeControlSetup();
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $setup['manager']);

    expect(fn () => $changeRequest->refresh()->update(['title' => 'Sneaky rename']))
        ->toThrow(LogicException::class, 'A submitted change request is frozen while it awaits the customer decision.');

    $this->actingAs($setup['manager'])
        ->put(route('change-requests.assessment.update', $changeRequest), ['allocations' => [], 'affected_items' => []])
        ->assertForbidden();
});

test('approval records an immutable response and mints the next baseline version', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'baseline' => $baseline, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $response = $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Approved, 'Go ahead.');

    $changeRequest->refresh();
    $minted = $changeRequest->mintedBaseline;

    expect($changeRequest->status)->toBe(ChangeRequestStatus::Approved)
        ->and($changeRequest->decided_at)->not->toBeNull()
        ->and($response->decision)->toBe(ChangeRequestDecision::Approved)
        ->and($response->snapshot_id)->toBe($changeRequest->customer_snapshot_id)
        ->and($response->stakeholder_name)->toBe('Anders Vik')
        ->and(fn () => $response->update(['comment' => 'Edited']))->toThrow(LogicException::class)
        ->and($minted)->not->toBeNull()
        ->and($minted->version)->toBe(2)
        ->and($minted->status)->toBe(BaselineStatus::Approved)
        ->and($minted->contract_value->amount)->toBe(2400000)
        ->and($minted->rate_card_version_id)->toBe($baseline->rate_card_version_id);

    $minted->load(['items', 'allocations']);
    $newDeliverable = $minted->items->firstWhere('title', 'Supplier portal module');
    $shiftedMilestone = $minted->items->firstWhere('title', 'Go-live');

    // Items and role mix carried forward, the go-live milestone moved by the
    // structured day count, and the change itself became a valued deliverable
    // carrying the CR role mix — values still sum to the new contract value.
    expect($minted->items)->toHaveCount(4)
        ->and($newDeliverable?->value?->amount)->toBe(400000)
        ->and($shiftedMilestone?->baseline_date?->toDateString())->toBe(today()->addDays(40)->toDateString())
        ->and($minted->allocations)->toHaveCount(5)
        ->and($minted->allocations->where('baseline_item_id', $newDeliverable?->id))->toHaveCount(2)
        ->and($minted->costBudget()->amount)->toBe(810000 + 215000)
        ->and($baseline->refresh()->items)->toHaveCount(3)
        ->and($engagement->positionSummary(true)['contracted']['amount'])->toBe(2400000)
        ->and($engagement->positionSummary(true)['baselineVersion'])->toBe(2)
        ->and(AuditLog::query()->where('action', 'baseline.version_minted')->where('subject_id', $minted->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'change_request.approved')->where('subject_id', $changeRequest->id)->sole()->payload['baseline_version'])->toBe(2);

    expect(fn () => $changeRequest->update(['title' => 'Too late']))
        ->toThrow(LogicException::class, 'A decided change request is immutable — the decision is on record.');
});

test('approving a scope-creep-born change maps its work item to the minted deliverable', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'developer' => $developer, 'approver' => $approver] = $setup;

    $workItem = WorkItem::factory()->for($organization)->for($engagement)->create([
        'title' => 'Sync supplier catalogues',
        'state' => WorkItemState::InProgress,
        'estimate_value' => 2,
        'estimate_unit' => EstimateUnit::Days,
    ]);
    $workItem->triage(WorkItemTriageStatus::PotentialChange, $manager);

    $changeRequest = $workItem->changeRequest;

    expect($changeRequest->flagsContractualBreach())->toBeTrue()
        ->and($changeRequest->origin)->toBe(ChangeRequestOrigin::ScopeCreep)
        ->and($changeRequest->estimated_days)->toBe(2.0);

    $changeRequest->startAssessment($manager);
    $changeRequest->allocations()->create([
        'organization_id' => $organization->id,
        'rate_card_role_id' => $developer->id,
        'days' => '2',
    ]);
    $changeRequest->moveToProposal($manager);
    $changeRequest->update(['customer_price' => Money::fromCents(160000)]);
    $changeRequest->submitToCustomer(today()->addDays(7), $manager);

    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);

    $link = $workItem->refresh()->link;

    // The customer bought the work: the potential-change classification
    // settles to existing scope on the deliverable the approval minted.
    expect($link)->not->toBeNull()
        ->and($link->baselineItem->title)->toBe($changeRequest->title)
        ->and($link->baselineItem->baseline_id)->toBe($changeRequest->refresh()->minted_baseline_id)
        ->and($workItem->triage_status)->toBe(WorkItemTriageStatus::ExistingScope);
});

test('rejection is terminal and mints nothing', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Rejected, 'Not this quarter.');

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($changeRequest->decided_at)->not->toBeNull()
        ->and($changeRequest->minted_baseline_id)->toBeNull()
        ->and($engagement->baselines()->count())->toBe(1)
        ->and(fn () => $changeRequest->recordResponse($approver, ChangeRequestDecision::Approved))
        ->toThrow(ValidationException::class);
});

test('a clarification request returns the proposal to assessment with the record preserved', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::ClarificationRequested, 'Which suppliers are in scope?');

    $changeRequest->refresh();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::UnderAssessment)
        ->and($changeRequest->decided_at)->toBeNull()
        ->and($changeRequest->customer_snapshot_id)->not->toBeNull()
        ->and($changeRequest->responses)->toHaveCount(1)
        ->and(Snapshot::query()->where('subject_id', $changeRequest->id)->count())->toBe(2);

    // The reopened assessment resubmits with fresh snapshots and can then be approved.
    $changeRequest->update(['scope_added' => 'Only the two launch suppliers are in scope.']);
    $changeRequest->moveToProposal($manager);
    $changeRequest->submitToCustomer(today()->addDays(7), $manager);

    expect(Snapshot::query()->where('subject_id', $changeRequest->id)->count())->toBe(4);

    $changeRequest->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and($changeRequest->responses)->toHaveCount(2);
});

test('stakeholders without approval rights cannot respond', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $viewer = Stakeholder::factory()
        ->for($organization)
        ->for($engagement->customer)
        ->role(StakeholderRole::Viewer)
        ->create();

    expect(fn () => $changeRequest->refresh()->recordResponse($viewer, ChangeRequestDecision::Approved))
        ->toThrow(ValidationException::class);

    $this->get(URL::signedRoute('portal.change-requests.show', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $viewer->id,
    ]))->assertForbidden();
});

test('the portal shows the frozen customer proposal on a personally signed link', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $signed = URL::signedRoute('portal.change-requests.show', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $changeRequest->customer_snapshot_id,
    ]);

    $this->get($signed)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/change-request')
            ->where('proposal.price.amount', 400000)
            ->where('proposal.change_request.title', 'Supplier portal module')
            ->where('superseded', false)
            ->where('canRespond', true)
            ->where('stakeholder.name', 'Anders Vik'));

    // The same link without its signature proves nothing.
    $this->get(route('portal.change-requests.show', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $changeRequest->customer_snapshot_id,
    ]))->assertForbidden();
});

test('the portal records the decision immutably against the frozen snapshot', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $respondUrl = URL::signedRoute('portal.change-requests.respond', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $changeRequest->customer_snapshot_id,
    ]);

    $this->post($respondUrl, ['decision' => 'approved', 'comment' => 'Signed off in the portal.'])
        ->assertRedirect();

    $changeRequest->refresh();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::Approved)
        ->and($changeRequest->responses->sole()->comment)->toBe('Signed off in the portal.')
        ->and($changeRequest->responses->sole()->stakeholder_name)->toBe('Anders Vik');
});

test('members see the change request without cost, rates or margin', function () {
    $setup = changeControlSetup();
    $member = User::factory()->role(UserRole::Member)->for($setup['organization'])->create();
    $changeRequest = priceProposal($setup);

    $this->actingAs($member)
        ->get(route('change-requests.show', $changeRequest))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assessment.cost', null)
            ->where('assessment.suggestedPrice', null)
            ->where('assessment.margin', null)
            ->where('assessment.allocations.0.costPerDay', null)
            ->where('roles', [])
            ->where('can.viewCommercials', false)
            ->where('changeRequest.customerPrice.amount', 400000));
});

test('a stale portal link cannot decide on a revised proposal', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $staleSnapshotId = $changeRequest->refresh()->customer_snapshot_id;
    $staleRespond = URL::signedRoute('portal.change-requests.respond', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $staleSnapshotId,
    ]);

    // A clarification round revises the terms and freezes fresh snapshots.
    $changeRequest->recordResponse($approver, ChangeRequestDecision::ClarificationRequested, 'Is hosting included?');
    $changeRequest->refresh()->moveToProposal($manager);
    $changeRequest->update(['customer_price' => Money::fromCents(480000)]);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    // The old tab still shows €4,000 — its link cannot approve €4,800 terms.
    $this->post($staleRespond, ['decision' => 'approved'])->assertInvalid(['decision']);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::AwaitingApproval)
        ->and($changeRequest->responses)->toHaveCount(1);

    // The superseded link keeps showing what it always showed, read-only.
    $this->get(URL::signedRoute('portal.change-requests.show', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $staleSnapshotId,
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('proposal.price.amount', 400000)
            ->where('superseded', true)
            ->where('canRespond', false));

    // The fresh link decides as usual, recorded against the fresh snapshot.
    $this->post(URL::signedRoute('portal.change-requests.respond', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $changeRequest->customer_snapshot_id,
    ]), ['decision' => 'approved'])->assertRedirect();

    $changeRequest->refresh();

    expect($changeRequest->status)->toBe(ChangeRequestStatus::Approved)
        ->and($changeRequest->responses->firstWhere('decision', ChangeRequestDecision::Approved)?->snapshot_id)
        ->toBe($changeRequest->customer_snapshot_id);

    // Each page shows only the decisions made on its own terms: the €4,800
    // approval never bleeds into the superseded €4,000 page.
    $this->get(URL::signedRoute('portal.change-requests.show', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $staleSnapshotId,
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('superseded', true)
            ->has('responses', 1)
            ->where('responses.0.decision', 'clarification_requested'));

    $this->get(URL::signedRoute('portal.change-requests.show', [
        'changeRequest' => $changeRequest->id,
        'stakeholder' => $approver->id,
        'snapshot' => $changeRequest->customer_snapshot_id,
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('superseded', false)
            ->has('responses', 1)
            ->where('responses.0.decision', 'approved'));
});

test('a decision is refused under the lock when the displayed proposal is no longer current', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);

    $staleSnapshotId = $changeRequest->refresh()->customer_snapshot_id;

    // The revision lands after the approver's page passed its own check.
    $changeRequest->recordResponse($approver, ChangeRequestDecision::ClarificationRequested);
    $changeRequest->refresh()->moveToProposal($manager);
    $changeRequest->update(['customer_price' => Money::fromCents(480000)]);
    $changeRequest->submitToCustomer(today()->addDays(14), $manager);
    $changeRequest->refresh();

    expect(fn () => $changeRequest->recordResponse($approver, ChangeRequestDecision::Approved, null, $staleSnapshotId))
        ->toThrow(ValidationException::class);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::AwaitingApproval)
        ->and($changeRequest->responses)->toHaveCount(1);
});

test('approval rebases schedule impact when another change advanced the baseline first', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer, 'milestone' => $milestone, 'approver' => $approver] = $setup;

    $first = priceProposal($setup);
    $first->submitToCustomer(today()->addDays(14), $manager);

    // A second change assessed against the same v1 go-live milestone.
    $second = $engagement->draftChangeRequest([
        'title' => 'Warehouse labelling',
        'what' => 'Labelling for the second warehouse.',
        'origin' => ChangeRequestOrigin::Meeting,
    ], $manager);
    $second->startAssessment($manager);
    $second->allocations()->create([
        'organization_id' => $second->organization_id,
        'rate_card_role_id' => $developer->id,
        'days' => '1',
    ]);
    $second->update(['impact_milestone_id' => $milestone->id, 'impact_days' => 7]);
    $second->moveToProposal($manager);
    $second->update(['customer_price' => Money::fromCents(100000)]);
    $second->submitToCustomer(today()->addDays(14), $manager);

    $first->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);
    $second->refresh()->recordResponse($approver, ChangeRequestDecision::Approved);

    $v3 = $engagement->approvedBaseline();
    $goLive = $v3?->items->firstWhere('title', 'Go-live');
    $minted = AuditLog::query()->where('action', 'baseline.version_minted')->where('subject_id', $v3?->id)->sole();

    // v2 moved go-live +10; v3 rebases the second reference through the item
    // lineage and composes +7 on top — day counts, never absolute dates.
    expect($v3?->version)->toBe(3)
        ->and($goLive?->baseline_date?->toDateString())->toBe(today()->addDays(47)->toDateString())
        ->and($v3?->contract_value->amount)->toBe(2500000)
        ->and($minted->payload['schedule_impact']['applied'])->toBeTrue()
        ->and($minted->payload['schedule_impact']['milestone'])->toBe('Go-live')
        ->and($minted->payload['schedule_impact']['days'])->toBe(7);
});

test('a proposal whose role mix was cleared cannot be submitted', function () {
    $setup = changeControlSetup();
    ['manager' => $manager] = $setup;
    $changeRequest = priceProposal($setup);

    // The assessment stays editable on the proposal stage — clear the mix.
    $this->actingAs($manager)
        ->put(route('change-requests.assessment.update', $changeRequest), [
            'allocations' => [],
            'affected_items' => [],
        ])
        ->assertRedirect(route('change-requests.show', $changeRequest));

    $this->actingAs($manager)
        ->post(route('change-requests.submit', $changeRequest), ['respond_by' => today()->addDays(14)->toDateString()])
        ->assertInvalid(['allocations']);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::CustomerProposal)
        ->and(Snapshot::query()->where('subject_id', $changeRequest->id)->count())->toBe(0);
});

test('submission requires a stakeholder with approval rights', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);

    $approver->delete();

    $this->actingAs($manager)
        ->post(route('change-requests.submit', $changeRequest), ['respond_by' => today()->addDays(14)->toDateString()])
        ->assertInvalid(['approvers']);

    expect($changeRequest->refresh()->status)->toBe(ChangeRequestStatus::CustomerProposal)
        ->and($changeRequest->submitted_at)->toBeNull()
        ->and(Snapshot::query()->where('subject_id', $changeRequest->id)->count())->toBe(0);

    Notification::assertNothingSent();
});

test('reminders go to approvers near the deadline, at most once a day', function () {
    Notification::fake();

    $setup = changeControlSetup();
    ['manager' => $manager, 'approver' => $approver] = $setup;
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(2), $manager);

    $this->artisan('change-requests:remind')->assertSuccessful();

    Notification::assertSentToTimes($approver, ChangeRequestReminder::class, 1);
    expect($changeRequest->refresh()->last_reminded_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'change_request.reminded')->where('subject_id', $changeRequest->id)->exists())->toBeTrue();

    // Within the same day nothing new goes out; a day later it nudges again.
    $this->artisan('change-requests:remind')->assertSuccessful();
    Notification::assertSentToTimes($approver, ChangeRequestReminder::class, 1);

    $this->travel(25)->hours();
    $this->artisan('change-requests:remind')->assertSuccessful();
    Notification::assertSentToTimes($approver, ChangeRequestReminder::class, 2);
});

test('proposals far from their deadline are not nagged', function () {
    Notification::fake();

    $setup = changeControlSetup();
    $changeRequest = priceProposal($setup);
    $changeRequest->submitToCustomer(today()->addDays(30), $setup['manager']);

    $this->artisan('change-requests:remind')->assertSuccessful();

    Notification::assertNotSentTo($setup['approver'], ChangeRequestReminder::class);
    expect($changeRequest->refresh()->last_reminded_at)->toBeNull();
});
