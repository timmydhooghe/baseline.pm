<?php

use App\Enums\BaselineDecision;
use App\Enums\BaselineStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Decision;
use App\Models\Deliverable;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Risk;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use App\Models\User;
use App\Notifications\BaselineSubmitted;
use App\Notifications\PortalLoginLink;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every key in a rendered portal payload, flattened, that smells like the
 * money internals the portal must never carry (FA-27). Keys only — free text
 * may legitimately contain words like "corporate".
 *
 * @return list<string>
 */
function portalForbiddenKeys(mixed $value, string $path = ''): array
{
    if (! is_array($value)) {
        return [];
    }

    $hits = [];

    foreach ($value as $key => $child) {
        $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

        if (is_string($key) && preg_match('/cost|margin|rate|burn|budget|exposure|allocation|sell/i', $key) === 1) {
            $hits[] = $keyPath;
        }

        $hits = array_merge($hits, portalForbiddenKeys($child, $keyPath));
    }

    return $hits;
}

/**
 * The full Inertia prop tree a portal URL renders, as plain arrays, ready
 * for the leakage scan.
 *
 * @return array<string, mixed>
 */
function portalPageProps(TestResponse $response): array
{
    /** @var array{props: mixed} $page */
    $page = $response->viewData('page');

    return (array) json_decode((string) json_encode($page['props']), true);
}

/**
 * The burnSetup() engagement grown into a full portal surface: a deliverable
 * and a change request awaiting the customer, a shared and an internal risk,
 * a confirmed shared decision, a late customer-owed and an internal
 * dependency, and a published weekly report. Anders Vik holds approval
 * rights; mail is faked because publishing notifies every stakeholder.
 *
 * @return array<string, mixed>
 */
function portalHubSetup(): array
{
    Notification::fake();

    $setup = burnSetup();

    $approver = Stakeholder::factory()
        ->for($setup['organization'])
        ->for($setup['customer'])
        ->role(StakeholderRole::Approver)
        ->create(['name' => 'Anders Vik', 'email' => 'anders@acme.example']);

    $deliverable = Deliverable::query()->where('baseline_item_id', $setup['checkout']->id)->firstOrFail();
    $deliverable->forceFill([
        'status' => DeliverableStatus::AwaitingAcceptance,
        'respond_by' => now()->addDays(3),
        'submitted_at' => now(),
        'review_snapshot_id' => Snapshot::capture($deliverable, ['kind' => 'internal_review'])->id,
        'customer_snapshot_id' => Snapshot::capture($deliverable, ['kind' => 'customer_review'])->id,
    ])->save();

    $changeRequest = ChangeRequest::factory()->for($setup['organization'])->for($setup['engagement'])->create([
        'title' => 'Add supplier portal',
        'customer_price' => Money::fromCents(750000),
    ]);
    $changeRequest->forceFill([
        'status' => ChangeRequestStatus::AwaitingApproval,
        'respond_by' => now()->addDays(2),
        'submitted_at' => now(),
        'customer_snapshot_id' => Snapshot::capture($changeRequest, ['kind' => 'customer_review'])->id,
    ])->save();

    Risk::factory()->for($setup['organization'])->for($setup['engagement'])->create([
        'title' => 'Legacy data quality',
        'description' => 'The migration source is inconsistent.',
        'visibility' => RecordVisibility::Shared,
        'mitigation' => 'Early data audit.',
    ]);
    Risk::factory()->for($setup['organization'])->for($setup['engagement'])->create([
        'title' => 'Internal staffing gap',
    ]);

    $decision = Decision::factory()->for($setup['organization'])->for($setup['engagement'])->create([
        'title' => 'Postpone SSO integration',
        'visibility' => RecordVisibility::Shared,
        'decision' => 'SSO moves to phase two.',
        'decided_on' => now()->subDays(3)->toDateString(),
    ]);
    $decision->confirm($setup['manager']);

    Decision::factory()->for($setup['organization'])->for($setup['engagement'])->create([
        'title' => 'Internal tooling decision',
        'decision' => 'Keep the current stack.',
        'decided_on' => now()->subDays(2)->toDateString(),
    ]);

    Dependency::factory()->for($setup['organization'])->for($setup['engagement'])->owedByCustomer($approver)->late(3)->create([
        'title' => 'Provide SSO metadata',
        'description' => 'Metadata XML for the staging tenant.',
    ]);
    Dependency::factory()->for($setup['organization'])->for($setup['engagement'])->create([
        'title' => 'Order the load-test environment',
    ]);

    $setup['engagement']->publishWeeklyReport(lastWeek(), $setup['manager']);

    return [...$setup, 'approver' => $approver, 'deliverable' => $deliverable, 'changeRequest' => $changeRequest];
}

/**
 * A draft baseline carrying one valued deliverable and a dated milestone,
 * submitted for customer approval — the acknowledgement path stands in for a
 * role mix. Anders Vik may approve; Vera View may not.
 *
 * @return array<string, mixed>
 */
function portalBaselineSetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $organization = $manager->organization;

    $customer = Customer::factory()->for($organization)->create(['name' => 'Acme Industries']);
    $engagement = Engagement::factory()
        ->for($organization)
        ->for($customer)
        ->status(EngagementStatus::PreparingBaseline)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'contract_value' => Money::fromCents(2000000),
    ]);

    BaselineItem::factory()->for($organization)->for($baseline)->completeDeliverable()->create([
        'title' => 'Checkout flow',
        'description' => 'The full purchase funnel.',
        'value' => Money::fromCents(2000000),
        'position' => 1,
    ]);
    BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live',
        'position' => 2,
    ]);

    $approver = Stakeholder::factory()
        ->for($organization)
        ->for($customer)
        ->role(StakeholderRole::Approver)
        ->create(['name' => 'Anders Vik']);
    $viewer = Stakeholder::factory()
        ->for($organization)
        ->for($customer)
        ->create(['name' => 'Vera View']);

    foreach ($baseline->completenessChecks() as $check) {
        if (! $check['passed'] && ! $check['acknowledged']) {
            $baseline->acknowledgeCheck($check['key'], $manager);
        }
    }

    $baseline->submitForApproval($manager);

    return [
        'manager' => $manager,
        'organization' => $organization,
        'customer' => $customer,
        'engagement' => $engagement,
        'baseline' => $baseline->refresh(),
        'approver' => $approver,
        'viewer' => $viewer,
    ];
}

function signedBaselineUrl(string $route, Baseline $baseline, Stakeholder $stakeholder, ?string $snapshot = null): string
{
    return URL::signedRoute($route, [
        'baseline' => $baseline->id,
        'stakeholder' => $stakeholder->id,
        'snapshot' => $snapshot ?? $baseline->customer_snapshot_id,
    ]);
}

/*
|--------------------------------------------------------------------------
| Magic-link sign-in
|--------------------------------------------------------------------------
*/

test('requesting a sign-in link emails every stakeholder record behind the address', function () {
    Notification::fake();

    $first = Stakeholder::factory()->create(['email' => 'dana@acme.example']);
    $second = Stakeholder::factory()->create(['email' => 'dana@acme.example']);
    Stakeholder::factory()->create(['email' => 'someone.else@acme.example']);

    $this->post(route('portal.login.request'), ['email' => 'dana@acme.example'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($first, PortalLoginLink::class);
    Notification::assertSentTo($second, PortalLoginLink::class);
    Notification::assertCount(2);
});

test('an unknown address gets the same answer and no mail', function () {
    Notification::fake();

    $this->post(route('portal.login.request'), ['email' => 'nobody@acme.example'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('the emailed link signs the stakeholder in and expires', function () {
    $stakeholder = Stakeholder::factory()->create();

    /* Unsigned and expired links are refused. */
    $this->get(route('portal.login.consume', ['stakeholder' => $stakeholder->id]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('portal.login.consume', now()->subMinute(), ['stakeholder' => $stakeholder->id]))->assertForbidden();
    $this->assertGuest('stakeholder');

    $this->get(URL::temporarySignedRoute('portal.login.consume', now()->addMinutes(30), ['stakeholder' => $stakeholder->id]))
        ->assertRedirect(route('portal.home'));

    $this->assertAuthenticatedAs($stakeholder, 'stakeholder');
});

test('the portal front door routes by session state', function () {
    $this->get(route('portal.welcome'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('portal/login'));

    $stakeholder = Stakeholder::factory()->create();

    $this->actingAs($stakeholder, 'stakeholder')
        ->get(route('portal.welcome'))
        ->assertRedirect(route('portal.home'));
});

test('guests bounced off the portal land on the portal sign-in, not the internal login', function () {
    $this->get(route('portal.home'))->assertRedirect(route('portal.welcome'));
});

test('signing out ends the stakeholder session', function () {
    $stakeholder = Stakeholder::factory()->create();

    $this->actingAs($stakeholder, 'stakeholder')
        ->post(route('portal.logout'))
        ->assertRedirect(route('portal.welcome'));

    $this->assertGuest('stakeholder');
});

/*
|--------------------------------------------------------------------------
| Portal home & engagement hub
|--------------------------------------------------------------------------
*/

test('the portal home lists only portal-visible engagements of the stakeholder customer', function () {
    $setup = portalHubSetup();

    Engagement::factory()->for($setup['organization'])->for($setup['customer'])
        ->status(EngagementStatus::Completed)->create(['name' => 'Retainer 2025']);
    Engagement::factory()->for($setup['organization'])->for($setup['customer'])
        ->status(EngagementStatus::Draft)->create(['name' => 'Unshared draft']);
    Engagement::factory()->for($setup['organization'])
        ->for(Customer::factory()->for($setup['organization'])->create(['name' => 'Globex']))
        ->status(EngagementStatus::Active)->create(['name' => 'Globex rebuild']);

    $this->actingAs($setup['approver'], 'stakeholder')
        ->get(route('portal.home'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->has('engagements', 2)
            ->where('engagements.0.name', 'ERP rollout')
            ->where('engagements.0.baselineVersion', 1)
            ->where('engagements.0.awaitingCount', 2)
            ->where('engagements.0.owedCount', 1)
            ->where('engagements.1.name', 'Retainer 2025'));
});

test('a single shared engagement goes straight to its hub', function () {
    $setup = burnSetup();
    $viewer = Stakeholder::factory()->for($setup['organization'])->for($setup['customer'])->create();

    $this->actingAs($viewer, 'stakeholder')
        ->get(route('portal.home'))
        ->assertRedirect(route('portal.engagements.show', ['engagement' => $setup['engagement']->id]));
});

test('the engagement hub shows the shared records and what awaits the customer', function () {
    $setup = portalHubSetup();

    $this->actingAs($setup['approver'], 'stakeholder')
        ->get(route('portal.engagements.show', ['engagement' => $setup['engagement']->id]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/engagement')
            ->where('engagement.name', 'ERP rollout')
            ->where('baseline.version', 1)
            ->where('baseline.contractValue.amount', 5000000)
            ->has('actions', 2)
            ->where('actions.0.type', 'change_request')
            ->where('actions.0.title', 'Add supplier portal')
            ->where('actions.1.type', 'deliverable')
            ->where('actions.1.title', 'Checkout flow')
            ->has('scope.deliverables', 2)
            ->has('dependencies', 1)
            ->where('dependencies.0.title', 'Provide SSO metadata')
            ->where('dependencies.0.late', true)
            ->where('dependencies.0.delayDays', 3)
            ->has('risks', 1)
            ->where('risks.0.title', 'Legacy data quality')
            ->has('decisions', 1)
            ->where('decisions.0.title', 'Postpone SSO integration')
            ->has('changeRequests', 1)
            ->has('reports', 1));
});

test('review links are minted only for roles that may respond', function () {
    $setup = portalHubSetup();
    $viewer = Stakeholder::factory()->for($setup['organization'])->for($setup['customer'])->create();

    $this->actingAs($setup['approver'], 'stakeholder')
        ->get(route('portal.engagements.show', ['engagement' => $setup['engagement']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->whereNot('actions.0.url', null)
            ->whereNot('actions.1.url', null));

    $this->actingAs($viewer, 'stakeholder')
        ->get(route('portal.engagements.show', ['engagement' => $setup['engagement']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.0.url', null)
            ->where('actions.1.url', null));
});

test('the hub is unreachable across customers and organizations', function () {
    $setup = burnSetup();

    $otherCustomerContact = Stakeholder::factory()
        ->for($setup['organization'])
        ->for(Customer::factory()->for($setup['organization'])->create())
        ->role(StakeholderRole::Approver)
        ->create();
    $otherOrganizationContact = Stakeholder::factory()->role(StakeholderRole::Approver)->create();

    $this->actingAs($otherCustomerContact, 'stakeholder')
        ->get(route('portal.engagements.show', ['engagement' => $setup['engagement']->id]))
        ->assertNotFound();

    $this->actingAs($otherOrganizationContact, 'stakeholder')
        ->get(route('portal.engagements.show', ['engagement' => $setup['engagement']->id]))
        ->assertNotFound();
});

test('no portal page ever exposes a cost, rate, margin or burn key', function () {
    $setup = portalHubSetup();

    Engagement::factory()->for($setup['organization'])->for($setup['customer'])
        ->status(EngagementStatus::Completed)->create(['name' => 'Retainer 2025']);

    $report = $setup['engagement']->reports()->firstOrFail();

    $pages = [
        route('portal.home'),
        route('portal.engagements.show', ['engagement' => $setup['engagement']->id]),
        URL::signedRoute('portal.reports.show', [
            'report' => $report->id,
            'stakeholder' => $setup['approver']->id,
            'snapshot' => $report->customer_snapshot_id,
        ]),
    ];

    foreach ($pages as $url) {
        $response = $this->actingAs($setup['approver'], 'stakeholder')->get($url)->assertSuccessful();

        expect(portalForbiddenKeys(portalPageProps($response)))->toBe([]);
    }
});

/*
|--------------------------------------------------------------------------
| Baseline approval flow
|--------------------------------------------------------------------------
*/

test('submitting a baseline notifies customer approvers with a personally signed link', function () {
    Notification::fake();

    $setup = portalBaselineSetup();

    Notification::assertSentTo($setup['approver'], BaselineSubmitted::class, function (BaselineSubmitted $notification) use ($setup): bool {
        $url = $notification->toMail($setup['approver'])->actionUrl;

        return str_contains((string) $url, "portal/baselines/{$setup['baseline']->id}/review/{$setup['approver']->id}")
            && str_contains((string) $url, 'signature=');
    });
    Notification::assertNotSentTo($setup['viewer'], BaselineSubmitted::class);
});

test('a signed link opens the frozen customer submission for review', function () {
    $setup = portalBaselineSetup();
    $baseline = $setup['baseline'];

    $response = $this->get(signedBaselineUrl('portal.baselines.show', $baseline, $setup['approver']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/baseline')
            ->where('submission.kind', 'customer_review')
            ->where('submission.baseline.version', 1)
            ->where('submission.baseline.contract_value.amount', 2000000)
            ->where('stakeholder.name', 'Anders Vik')
            ->where('superseded', false)
            ->where('canRespond', true)
            ->has('submission.items', 2));

    /* The frozen submission renders without a single money-internals key. */
    expect(portalForbiddenKeys(portalPageProps($response)))->toBe([]);

    /* An unsigned link is refused. */
    $this->get(route('portal.baselines.show', [
        'baseline' => $baseline->id,
        'stakeholder' => $setup['approver']->id,
        'snapshot' => $baseline->customer_snapshot_id,
    ]))->assertForbidden();

    /* A signed link never opens the internal twin. */
    $this->get(signedBaselineUrl('portal.baselines.show', $baseline, $setup['approver'], $baseline->review_snapshot_id))
        ->assertNotFound();
});

test('viewers and other customers cannot open the baseline review', function () {
    $setup = portalBaselineSetup();
    $baseline = $setup['baseline'];

    $this->get(signedBaselineUrl('portal.baselines.show', $baseline, $setup['viewer']))->assertForbidden();

    $otherCustomerApprover = Stakeholder::factory()
        ->for($setup['organization'])
        ->for(Customer::factory()->for($setup['organization'])->create())
        ->role(StakeholderRole::Approver)
        ->create();

    $this->get(signedBaselineUrl('portal.baselines.show', $baseline, $otherCustomerApprover))->assertForbidden();
    $this->post(signedBaselineUrl('portal.baselines.respond', $baseline, $setup['viewer']), ['decision' => 'approved'])
        ->assertForbidden();
});

test('approving through the portal commits the baseline and activates the engagement', function () {
    $setup = portalBaselineSetup();
    $baseline = $setup['baseline'];

    $this->post(signedBaselineUrl('portal.baselines.respond', $baseline, $setup['approver']), [
        'decision' => 'approved',
        'comment' => 'Signed off.',
    ])->assertRedirect();

    $baseline->refresh();
    $response = $baseline->responses->sole();

    expect($baseline->status)->toBe(BaselineStatus::Approved)
        ->and($setup['engagement']->refresh()->status)->toBe(EngagementStatus::Active)
        ->and($response->decision)->toBe(BaselineDecision::Approved)
        ->and($response->stakeholder_name)->toBe('Anders Vik')
        ->and($response->comment)->toBe('Signed off.')
        ->and($response->snapshot_id)->toBe($baseline->customer_snapshot_id)
        ->and(Deliverable::query()->where('engagement_id', $setup['engagement']->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'baseline.approved')->sole()->payload['approved_by'])->toBe('Anders Vik');
});

test('rejection and clarification return the draft to the builder', function (BaselineDecision $decision) {
    $setup = portalBaselineSetup();
    $baseline = $setup['baseline'];

    $this->post(signedBaselineUrl('portal.baselines.respond', $baseline, $setup['approver']), [
        'decision' => $decision->value,
        'comment' => 'Please revisit.',
    ])->assertRedirect();

    $baseline->refresh();

    expect($baseline->status)->toBe(BaselineStatus::Draft)
        ->and($setup['engagement']->refresh()->status)->toBe(EngagementStatus::PreparingBaseline)
        ->and($baseline->responses->sole()->decision)->toBe($decision)
        ->and(AuditLog::query()->where('action', 'baseline.returned_to_draft')->sole()->payload['reason'])->toBe($decision->value);
})->with([
    'rejected' => BaselineDecision::Rejected,
    'clarification requested' => BaselineDecision::ClarificationRequested,
]);

test('a superseded snapshot link goes read-only and can no longer decide', function () {
    $setup = portalBaselineSetup();
    $baseline = $setup['baseline'];
    $staleSnapshot = $baseline->customer_snapshot_id;

    $baseline->returnToDraft('clarification_requested', $setup['approver']);
    $baseline->refresh()->submitForApproval($setup['manager']);
    $baseline->refresh();

    $this->get(signedBaselineUrl('portal.baselines.show', $baseline, $setup['approver'], $staleSnapshot))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('superseded', true)
            ->where('canRespond', false));

    $this->post(signedBaselineUrl('portal.baselines.respond', $baseline, $setup['approver'], $staleSnapshot), [
        'decision' => 'approved',
    ])->assertInvalid(['decision']);

    expect($baseline->refresh()->status)->toBe(BaselineStatus::AwaitingApproval);
});

test('a decided baseline refuses further responses', function () {
    $setup = portalBaselineSetup();
    $baseline = $setup['baseline'];
    $respond = signedBaselineUrl('portal.baselines.respond', $baseline, $setup['approver']);

    $this->post($respond, ['decision' => 'approved']);
    $this->post($respond, ['decision' => 'rejected'])->assertInvalid(['decision']);

    expect($baseline->refresh()->status)->toBe(BaselineStatus::Approved)
        ->and($baseline->responses)->toHaveCount(1);
});
