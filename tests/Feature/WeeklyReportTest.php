<?php

use App\Enums\ChangeRequestStatus;
use App\Enums\DependencyParty;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BurnWeek;
use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Report;
use App\Models\Stakeholder;
use App\Models\User;
use App\Notifications\WeeklyReportPublished;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * Weekly evidence-based reporting (FA-26): drafts derived from the ledgers,
 * publishing as a frozen pair of snapshots — the customer one built without
 * cost, margin or internal records — diffed against the previous week and
 * sent to the stakeholders on personally signed links. The money fixture is
 * burnSetup() from tests/Pest.php.
 */

/**
 * The burn fixture plus a customer contact to send reports to.
 *
 * @return array<string, mixed>
 */
function reportSetup(): array
{
    $setup = burnSetup();

    $setup['contact'] = Stakeholder::factory()
        ->for($setup['organization'])
        ->for($setup['customer'])
        ->role(StakeholderRole::Approver)
        ->create(['name' => 'Anders Vik']);

    return $setup;
}

it('publishes the week as frozen twin snapshots and notifies the stakeholders', function (): void {
    Notification::fake();

    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer, 'contact' => $contact] = reportSetup();

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    $report = $engagement->publishWeeklyReport(lastWeek(), $manager);

    $internal = $report->reviewSnapshot;
    $customer = $report->customerSnapshot;

    expect($report->week_start->toDateString())->toBe(lastWeek()->toDateString())
        ->and($report->publishedBy?->id)->toBe($manager->id)
        ->and($internal->payload['kind'])->toBe('internal_report')
        ->and($internal->payload['commercials']['burn_week']['cost']['amount'])->toBe(225000)
        ->and($internal->verifyIntegrity())->toBeTrue()
        ->and($customer->payload['kind'])->toBe('customer_report')
        /* Cost and margin are built out structurally, never merely blanked. */
        ->and($customer->payload)->not->toHaveKey('commercials')
        ->and($customer->verifyIntegrity())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'report.published')->exists())->toBeTrue();

    Notification::assertSentTo($contact, WeeklyReportPublished::class);
});

it('builds the customer variant without internal-visibility records', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'contact' => $contact] = reportSetup();

    $engagement->registerRisk([
        'title' => 'Legacy exports keep failing validation',
        'probability' => RiskRating::High->value,
        'impact' => RiskRating::High->value,
        'status' => RiskStatus::Open->value,
        'visibility' => 'internal',
    ], $manager);
    $engagement->registerDependency([
        'title' => 'Staging environment access',
        'party' => DependencyParty::Internal,
        'responsible_user_id' => $manager->id,
        'required_on' => today()->addDays(3),
        'visibility' => 'internal',
    ], $manager);
    $engagement->registerDependency([
        'title' => 'Production database credentials',
        'party' => DependencyParty::Customer,
        'responsible_stakeholder_id' => $contact->id,
        'required_on' => today()->addDays(7),
        'visibility' => 'shared',
    ], $manager);

    /* The current week, so the records registered just now fall inside it. */
    $report = $engagement->publishWeeklyReport(BurnWeek::startOfWeekFor(now()), $manager);

    $internalEvents = array_column($report->reviewSnapshot->payload['changed'], 'event');
    $customerEvents = array_column($report->customerSnapshot->payload['changed'], 'event');

    expect($internalEvents)->toContain('risk.raised')
        ->and($customerEvents)->not->toContain('risk.raised')
        ->and($report->reviewSnapshot->payload['owed'])->toHaveCount(2)
        ->and($report->customerSnapshot->payload['owed'])->toHaveCount(1)
        ->and($report->customerSnapshot->payload['owed'][0]['responsible'])->toBe('Anders Vik');
});

it('diffs each deliverable against what the previous published report said', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'checkout' => $checkout] = reportSetup();

    $deliverable = Deliverable::query()->where('baseline_item_id', $checkout->id)->sole();

    $deliverable->update(['progress' => 20]);
    $engagement->publishWeeklyReport(lastWeek()->subWeek(), $manager);

    $deliverable->update(['progress' => 45]);
    $report = $engagement->publishWeeklyReport(lastWeek(), $manager);

    $payload = $report->reviewSnapshot->payload;

    expect($payload['previous']['week_start'])->toBe(lastWeek()->subWeek()->toDateString())
        ->and($payload['moved'][0]['record']['title'])->toBe('Checkout flow')
        ->and($payload['moved'][0]['progress'])->toBe(45)
        ->and($payload['moved'][0]['previous']['progress'])->toBe(20);
});

it('diffs against the last published report when a week was skipped', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'checkout' => $checkout] = reportSetup();

    $deliverable = Deliverable::query()->where('baseline_item_id', $checkout->id)->sole();

    $deliverable->update(['progress' => 20]);
    $engagement->publishWeeklyReport(lastWeek()->subWeeks(2), $manager);

    /* The week in between is never published; the deltas must survive the gap. */
    $deliverable->update(['progress' => 45]);
    $report = $engagement->publishWeeklyReport(lastWeek(), $manager);

    $payload = $report->reviewSnapshot->payload;

    expect($payload['previous']['week_start'])->toBe(lastWeek()->subWeeks(2)->toDateString())
        ->and($payload['moved'][0]['previous']['progress'])->toBe(20);
});

it('reports a risk raised and re-rated in the same week as both events', function (): void {
    ['manager' => $manager, 'engagement' => $engagement] = reportSetup();

    $risk = $engagement->registerRisk([
        'title' => 'Legacy exports keep failing validation',
        'probability' => RiskRating::Low->value,
        'impact' => RiskRating::Medium->value,
        'status' => RiskStatus::Open->value,
        'visibility' => 'internal',
    ], $manager);
    $risk->reassess([
        'probability' => RiskRating::High->value,
        'impact' => RiskRating::High->value,
    ], $manager);

    $report = $engagement->publishWeeklyReport(BurnWeek::startOfWeekFor(now()), $manager);

    $events = collect($report->reviewSnapshot->payload['changed'])
        ->where('record.id', $risk->id)
        ->keyBy('event');

    /* Raised at its opening rating — not rewritten to where it ended up. */
    expect($events->get('risk.raised')['detail'])->toBe('Low × Medium')
        ->and($events->get('risk.rerated')['detail'])->toBe('Low × Medium → High × High');
});

it('refuses to publish a week before the baseline started', function (): void {
    ['manager' => $manager, 'engagement' => $engagement] = reportSetup();

    expect(fn () => $engagement->publishWeeklyReport(BurnWeek::startOfWeekFor(now())->subWeeks(5), $manager))
        ->toThrow(ValidationException::class, 'Reporting starts with the baseline');

    expect(Report::query()->count())->toBe(0);
});

it('keeps a superseded decision in the week it was confirmed', function (): void {
    ['manager' => $manager, 'engagement' => $engagement] = reportSetup();

    $first = $engagement->recordDecision([
        'title' => 'SSO excluded from phase 1',
        'context' => 'Window too short.',
        'decision' => 'Excluded.',
        'decided_on' => today(),
        'visibility' => 'shared',
    ], $manager);
    $first->confirm($manager);

    $second = $engagement->recordDecision([
        'title' => 'SSO added to phase 2',
        'context' => 'Budget freed up.',
        'decision' => 'SSO ships in phase 2.',
        'decided_on' => today(),
        'supersedes_id' => $first->id,
        'visibility' => 'shared',
    ], $manager);
    $second->confirm($manager);

    $report = $engagement->publishWeeklyReport(BurnWeek::startOfWeekFor(now()), $manager);

    $confirmed = collect($report->reviewSnapshot->payload['changed'])
        ->where('event', 'decision.confirmed')
        ->pluck('record.id');

    /* Superseded is still confirmed history — the week's record stands. */
    expect($confirmed)->toContain($first->id)
        ->and($confirmed)->toContain($second->id);
});

it('freezes the publisher\'s name against later renames', function (): void {
    ['manager' => $manager, 'engagement' => $engagement] = reportSetup();

    $report = $engagement->publishWeeklyReport(lastWeek(), $manager);

    $manager->forceFill(['name' => 'Someone Else'])->save();

    expect($report->refresh()->published_by_name)->toBe('Dana Mertens');

    $this->actingAs($manager)
        ->get(route('reports.show', $report))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.publishedByName', 'Dana Mertens'));
});

it('publishes a week once and refuses to touch it afterwards', function (): void {
    ['manager' => $manager, 'engagement' => $engagement] = reportSetup();

    $report = $engagement->publishWeeklyReport(lastWeek(), $manager);

    expect(fn () => $engagement->publishWeeklyReport(lastWeek(), $manager))
        ->toThrow(ValidationException::class, 'already published');

    expect(fn () => $report->update(['week_start' => lastWeek()->subWeek()]))
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => $report->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

test('the ledger lists the weeks still owed a report beside the published ones', function () {
    ['manager' => $manager, 'engagement' => $engagement] = reportSetup();

    $report = $engagement->publishWeeklyReport(lastWeek(), $manager);

    $this->actingAs($manager)
        ->get(route('engagements.reports.index', $engagement))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/reports')
            /* Four finished weeks, one published. */
            ->has('due', 3)
            ->has('published', 1)
            ->where('published.0.id', $report->id)
            ->where('can.publish', true));

    /* A published week has no draft — the frozen report is its only story. */
    $this->actingAs($manager)
        ->get(route('engagements.reports.draft', [$engagement, lastWeek()->toDateString()]))
        ->assertRedirect(route('reports.show', $report));
});

test('a draft derives live and keeps commercials from roles without rate card access', function () {
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement] = reportSetup();

    $week = lastWeek()->toDateString();

    $this->actingAs($manager)
        ->get(route('engagements.reports.draft', [$engagement, $week]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/report')
            ->where('report.published', false)
            ->has('variants.internal.commercials')
            ->missing('variants.customer.commercials')
            ->where('can.publish', true));

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('engagements.reports.draft', [$engagement, $week]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/report')
            ->missing('variants.internal.commercials')
            ->where('can.publish', false));
});

test('publishing is a managing role\'s action', function () {
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement] = reportSetup();

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->post(route('engagements.reports.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
        ])
        ->assertForbidden();

    $this->actingAs($manager)
        ->post(route('engagements.reports.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
        ])
        ->assertRedirect();

    expect(Report::query()->count())->toBe(1);
});

test('a stakeholder reads the frozen customer report on their signed link only', function () {
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement, 'contact' => $contact] = reportSetup();

    ChangeRequest::factory()->for($organization)->for($engagement)->create([
        'status' => ChangeRequestStatus::AwaitingApproval,
        'title' => 'Add the SSO screen',
        'submitted_at' => now(),
        'respond_by' => now()->addDays(7),
    ]);

    $report = $engagement->publishWeeklyReport(BurnWeek::startOfWeekFor(now()), $manager);

    $signed = URL::signedRoute('portal.reports.show', [
        'report' => $report->id,
        'stakeholder' => $contact->id,
        'snapshot' => $report->customer_snapshot_id,
    ]);

    $this->get($signed)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/report')
            ->where('report.kind', 'customer_report')
            ->missing('report.commercials')
            ->where('report.changed', fn ($changed) => collect($changed)->pluck('event')->contains('change_request.submitted'))
            ->where('stakeholder.name', 'Anders Vik'));

    /* The same address without its signature proves nothing. */
    $this->get(route('portal.reports.show', [
        'report' => $report->id,
        'stakeholder' => $contact->id,
        'snapshot' => $report->customer_snapshot_id,
    ]))->assertForbidden();

    /* A signed link never opens the internal twin. */
    $this->get(URL::signedRoute('portal.reports.show', [
        'report' => $report->id,
        'stakeholder' => $contact->id,
        'snapshot' => $report->review_snapshot_id,
    ]))->assertNotFound();
});
