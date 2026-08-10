<?php

use App\Enums\BurnSource;
use App\Enums\EngagementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BurnEntry;
use App\Models\BurnWeek;
use App\Models\Deliverable;
use App\Models\User;
use App\Models\WorkItem;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use LogicException;

/**
 * Weekly burn entry (FA-16): the prefill hierarchy, the immutable weekly
 * snapshot, corrections as new entries, and the weeks nobody recorded. The
 * fixture — a €50,000 contract against a €12,000 cost budget — lives in
 * tests/Pest.php as burnSetup(), shared with the margin suite.
 */
it('records a week as an immutable snapshot and moves cost to date', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $week = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5, 'person_name' => 'Sara Peeters', 'source' => BurnSource::Worklog],
    ], $manager);

    expect($week->cost->amount)->toBe(225000)
        ->and($week->week_start->toDateString())->toBe(lastWeek()->toDateString())
        ->and($week->entries)->toHaveCount(1)
        ->and($week->entries->sole()->cost_per_day->amount)->toBe(45000)
        ->and($week->entries->sole()->source)->toBe(BurnSource::Worklog)
        ->and($week->rate_card_version_id)->not->toBeNull()
        ->and($engagement->recordedBurn()->amount)->toBe(225000);

    expect(AuditLog::query()->where('action', 'burn_week.recorded')->exists())->toBeTrue();
});

it('refuses to edit or delete a recorded week', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $week = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    expect(fn () => $week->update(['note' => 'Actually four days']))
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => $week->entries->sole()->update(['days' => '4']))
        ->toThrow(LogicException::class, 'immutable');

    expect(fn () => $week->delete())->toThrow(LogicException::class, 'governance history');
});

it('supersedes the earlier recording when a week is corrected', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $first = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    $second = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 4],
    ], $manager, 'Sara took Friday off');

    $first->refresh();

    expect($first->superseded_by_id)->toBe($second->id)
        ->and($first->isCurrent())->toBeFalse()
        ->and($second->isCurrent())->toBeTrue()
        /* Cost to date counts the correction, never both. */
        ->and($engagement->recordedBurn()->amount)->toBe(180000)
        ->and($engagement->burnWeeks()->count())->toBe(2)
        ->and($engagement->currentBurnWeeks()->count())->toBe(1);

    expect(AuditLog::query()->where('action', 'burn_week.corrected')->exists())->toBeTrue();
});

it('chains corrections so only the latest recording is ever current', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $first = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);
    $second = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 4],
    ], $manager);
    $third = $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 3],
    ], $manager);

    /* The chain reads forward: each recording points at the one that replaced it. */
    expect($first->refresh()->superseded_by_id)->toBe($second->id)
        ->and($second->refresh()->superseded_by_id)->toBe($third->id)
        ->and($third->isCurrent())->toBeTrue()
        ->and($engagement->currentBurnWeeks()->count())->toBe(1)
        ->and($engagement->recordedBurn()->amount)->toBe(135000);

    /* A superseded recording stays frozen, including its supersede stamp. */
    $first->refresh()->superseded_by_id = $third->id;

    expect(fn () => $first->save())->toThrow(LogicException::class, 'already superseded');
});

it('refuses lines that cannot be traced, priced or spent', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    expect(fn () => $engagement->recordBurnWeek(lastWeek(), [], $manager))
        ->toThrow(ValidationException::class);

    expect(fn () => $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 0],
    ], $manager))->toThrow(ValidationException::class);

    expect(fn () => $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 2, 'person_name' => 'Sara Peeters'],
        ['rate_card_role_id' => $developer->id, 'days' => 3, 'person_name' => 'Sara Peeters'],
    ], $manager))->toThrow(ValidationException::class);

    expect(fn () => $engagement->recordBurnWeek(BurnWeek::startOfWeekFor(now())->addWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 2],
    ], $manager))->toThrow(ValidationException::class, 'once it has started');

    expect(BurnWeek::query()->count())->toBe(0);
});

it('refuses a role from another rate card version', function (): void {
    ['manager' => $manager, 'organization' => $organization, 'engagement' => $engagement] = burnSetup();

    $later = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(50000), 'sell_per_day' => Money::fromCents(85000)],
    ]);

    expect(fn () => $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $later->roles->sole()->id, 'days' => 2],
    ], $manager))->toThrow(ValidationException::class, 'rate card version this engagement is priced against');
});

it('surfaces the finished weeks nobody recorded', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    /* Four weeks of baseline, four finished weeks waiting. */
    expect($engagement->unrecordedBurnWeeks())->toHaveCount(4);

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5],
    ], $manager);

    $missing = $engagement->refresh()->unrecordedBurnWeeks();

    expect($missing)->toHaveCount(3)
        ->and(array_map(fn (CarbonImmutable $week): string => $week->toDateString(), $missing))
        ->not->toContain(lastWeek()->toDateString());
});

it('stops chasing burn weeks once the engagement is archived', function (): void {
    ['engagement' => $engagement] = burnSetup();

    $engagement->forceFill(['status' => EngagementStatus::Archived])->save();

    expect($engagement->unrecordedBurnWeeks())->toBe([]);
});

it('prefills the week from logged time and derives the rest from progress', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'checkout' => $checkout] = burnSetup();

    $sara = User::factory()->for($engagement->organization)->create(['name' => 'Sara Peeters']);
    WorkItem::factory()->for($engagement->organization)->for($engagement)->create()
        ->addManualWorklog(20, lastWeek()->addDay()->toDateString(), $sara);

    /* The €30,000 deliverable is half done; the €20,000 one has not started. */
    Deliverable::query()->where('baseline_item_id', $checkout->id)->sole()->update(['progress' => 50]);

    $this->actingAs($manager)
        ->get(route('engagements.burn.index', ['engagement' => $engagement, 'week' => lastWeek()->toDateString()]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/burn')
            ->where('week.loggedHours', 20)
            ->where('week.recorded', false)
            /* €30,000 of €50,000 at 50% — weighted, not averaged. */
            ->where('week.weightedProgress', 0.3)
            ->where('week.lines.0.personName', 'Sara Peeters')
            ->where('week.lines.0.days', 2.5)
            ->where('week.lines.0.source', 'worklog')
            /* Nobody has booked Sara against a profile yet, so the rate is not invented. */
            ->where('week.lines.0.roleId', null)
            ->where('week.lines.0.cost', null)
            /* Both planned profiles still get a progress-derived estimate. */
            ->has('week.lines', 3)
            ->where('week.lines.1.roleName', 'Developer')
            ->where('week.lines.1.source', 'progress')
            ->where('week.lines.1.days', 6)
            ->where('week.lines.2.roleName', 'Delivery lead')
            /* 30% of five planned lead days, none recorded. */
            ->where('week.lines.2.days', 1.5)
            /* Every profile on the pinned card is offerable, planned or not. */
            ->has('week.roles', 3)
        );
});

it('remembers the profile a person was last recorded against', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $engagement->recordBurnWeek(lastWeek()->subWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 5, 'person_name' => 'Sara Peeters'],
    ], $manager);

    $sara = User::factory()->for($engagement->organization)->create(['name' => 'Sara Peeters']);
    WorkItem::factory()->for($engagement->organization)->for($engagement)->create()
        ->addManualWorklog(16, lastWeek()->addDay()->toDateString(), $sara);

    $this->actingAs($manager)
        ->get(route('engagements.burn.index', ['engagement' => $engagement, 'week' => lastWeek()->toDateString()]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('week.lines.0.personName', 'Sara Peeters')
            ->where('week.lines.0.roleId', $developer->id)
            ->where('week.lines.0.days', 2)
            ->where('week.lines.0.cost.amount', 90000)
            /* Sara covers the developer profile, so it does not also estimate. */
            ->has('week.lines', 2)
            ->where('week.lines.1.roleName', 'Delivery lead')
        );
});

it('reads a recorded week back from the ledger instead of re-suggesting it', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $engagement->recordBurnWeek(lastWeek(), [
        ['rate_card_role_id' => $developer->id, 'days' => 3, 'person_name' => 'Sara Peeters'],
    ], $manager);

    $this->actingAs($manager)
        ->get(route('engagements.burn.index', ['engagement' => $engagement, 'week' => lastWeek()->toDateString()]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('week.recorded', true)
            ->where('week.recordedByName', 'Dana Mertens')
            ->has('week.lines', 1)
            ->where('week.lines.0.days', 3)
            ->where('weeks.0.cost.formatted', '€ 1.350,00')
        );
});

it('records a week through the form and reports where it lands', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer, 'lead' => $lead] = burnSetup();

    $this->actingAs($manager)
        ->post(route('engagements.burn.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
            'lines' => [
                ['rate_card_role_id' => $developer->id, 'days' => '4.5', 'person_name' => 'Sara Peeters', 'source' => 'worklog'],
                ['rate_card_role_id' => $lead->id, 'days' => '1', 'person_name' => null, 'source' => 'progress'],
            ],
        ])
        ->assertRedirect();

    $week = $engagement->currentBurnWeeks()->with('entries')->sole();

    expect($week->cost->amount)->toBe(202500 + 60000)
        ->and($week->entries)->toHaveCount(2)
        ->and($engagement->recordedBurn()->format())->toBe('€ 2.625,00');
});

it('refuses more than seven days for one person but not for a whole profile', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $this->actingAs($manager)
        ->post(route('engagements.burn.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
            'lines' => [
                ['rate_card_role_id' => $developer->id, 'days' => '9', 'person_name' => 'Sara Peeters', 'source' => 'manual'],
            ],
        ])
        ->assertSessionHasErrors('lines.0.days');

    $this->actingAs($manager)
        ->post(route('engagements.burn.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
            'lines' => [
                ['rate_card_role_id' => $developer->id, 'days' => '9', 'source' => 'manual'],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(BurnEntry::query()->sum('days'))->toEqual(9);
});

it('keeps burn behind the roles that may read the rate card', function (): void {
    ['engagement' => $engagement, 'organization' => $organization, 'developer' => $developer] = burnSetup();

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('engagements.burn.index', $engagement))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('engagements.burn.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
            'lines' => [['rate_card_role_id' => $developer->id, 'days' => '2', 'source' => 'manual']],
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('engagements.margin.show', $engagement))
        ->assertForbidden();
});

it('refuses to record burn on an archived engagement', function (): void {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = burnSetup();

    $engagement->forceFill(['status' => EngagementStatus::Archived])->save();

    $this->actingAs($manager)
        ->post(route('engagements.burn.store', $engagement), [
            'week_start' => lastWeek()->toDateString(),
            'lines' => [['rate_card_role_id' => $developer->id, 'days' => '2', 'source' => 'manual']],
        ])
        ->assertForbidden();
});
