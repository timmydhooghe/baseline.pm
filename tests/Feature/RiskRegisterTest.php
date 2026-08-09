<?php

use App\Enums\BaselineStatus;
use App\Enums\EngagementStatus;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Engagement;
use App\Models\RateCardRole;
use App\Models\Risk;
use App\Models\RiskRevision;
use App\Models\User;
use App\ValueObjects\Money;
use Inertia\Testing\AssertableInertia;

/**
 * A delivery manager on an active engagement with an approved baseline
 * priced at a two-role rate card: a developer at €450/day cost and a lead at
 * €600/day.
 *
 * @return array<string, mixed>
 */
function riskSetup(): array
{
    $manager = User::factory()->role(UserRole::DeliveryManager)->create(['name' => 'Dana Mertens']);
    $organization = $manager->organization;

    $version = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
        ['name' => 'Delivery lead', 'cost_per_day' => Money::fromCents(60000), 'sell_per_day' => Money::fromCents(95000)],
    ]);

    $engagement = Engagement::factory()
        ->for($organization)
        ->status(EngagementStatus::Active)
        ->create(['name' => 'ERP rollout']);

    $baseline = Baseline::factory()->for($organization)->for($engagement)->create([
        'rate_card_version_id' => $version->id,
        'contract_value' => Money::fromCents(2000000),
    ]);

    $milestone = BaselineItem::factory()->for($organization)->for($baseline)->completeMilestone()->create([
        'title' => 'Go-live',
        'baseline_date' => today()->addDays(30),
    ]);

    $baseline->forceFill(['status' => BaselineStatus::Approved, 'approved_at' => now()])->save();

    return [
        'manager' => $manager,
        'organization' => $organization,
        'engagement' => $engagement,
        'milestone' => $milestone,
        'version' => $version,
        'developer' => $version->roles->firstWhere('name', 'Developer'),
        'lead' => $version->roles->firstWhere('name', 'Delivery lead'),
    ];
}

/**
 * The payload the risk form posts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function riskPayload(array $overrides = []): array
{
    return [
        'title' => 'Migration source data is unreliable',
        'description' => 'The legacy exports have failed validation twice.',
        'probability' => RiskRating::Medium->value,
        'impact' => RiskRating::High->value,
        'status' => RiskStatus::Open->value,
        'mitigation' => 'Run a full dry migration in week 3.',
        'visibility' => 'internal',
        ...$overrides,
    ];
}

test('raising a risk pins the baseline rate card and freezes the opening rating', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'milestone' => $milestone, 'version' => $version] = riskSetup();

    $this->actingAs($manager)
        ->post(route('engagements.risks.store', $engagement), riskPayload([
            'owner_id' => $manager->id,
            'links' => [['type' => BaselineItem::class, 'id' => $milestone->id]],
        ]))
        ->assertRedirect();

    $risk = Risk::query()->sole();

    expect($risk->rate_card_version_id)->toBe($version->id)
        ->and($risk->score())->toBe(6)
        ->and($risk->owner_id)->toBe($manager->id)
        ->and($risk->links->sole()->threatened_id)->toBe($milestone->id)
        ->and($risk->revisions)->toHaveCount(1)
        ->and($risk->revisions->sole()->score)->toBe(6)
        ->and(AuditLog::query()->where('action', 'risk.registered')->exists())->toBeTrue();
});

test('exposure is priced from the pinned rate card and weighted by probability', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer, 'lead' => $lead] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);

    $this->actingAs($manager)
        ->put(route('risks.exposure.update', $risk), [
            'lines' => [
                ['rate_card_role_id' => $developer->id, 'days' => 10],
                ['rate_card_role_id' => $lead->id, 'days' => 2.5],
            ],
        ])
        ->assertRedirect();

    $risk->refresh()->load('exposures.role');

    // 10 × €450 + 2.5 × €600 = €6,000; medium probability weights it to half.
    expect($risk->exposure()->amount)->toBe(600000)
        ->and($risk->weightedExposure()->amount)->toBe(300000)
        ->and($engagement->riskExposure()['weighted']->amount)->toBe(300000);
});

test('exposure refuses a role from another rate card version', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'organization' => $organization] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);

    $newer = $organization->publishRateCardVersion([
        ['name' => 'Developer', 'cost_per_day' => Money::fromCents(50000), 'sell_per_day' => Money::fromCents(80000)],
    ], $manager);

    $this->actingAs($manager)
        ->put(route('risks.exposure.update', $risk), [
            'lines' => [['rate_card_role_id' => $newer->roles->sole()->id, 'days' => 4]],
        ])
        ->assertInvalid('lines.0.rate_card_role_id');

    expect($risk->refresh()->exposures)->toBeEmpty();
});

test('exposure refuses the same role twice', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);

    $this->actingAs($manager)
        ->put(route('risks.exposure.update', $risk), [
            'lines' => [
                ['rate_card_role_id' => $developer->id, 'days' => 4],
                ['rate_card_role_id' => $developer->id, 'days' => 2],
            ],
        ])
        ->assertInvalid('lines');
});

test('a re-rating appends a revision and a risk that got worse is flagged', function () {
    ['manager' => $manager, 'engagement' => $engagement] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload([
        'probability' => RiskRating::Low,
        'impact' => RiskRating::Medium,
    ]), $manager);

    expect($risk->isWorsening())->toBeFalse();

    $this->actingAs($manager)
        ->patch(route('risks.update', $risk), riskPayload([
            'probability' => RiskRating::High->value,
            'impact' => RiskRating::High->value,
            'note' => 'Second export failed validation.',
        ]))
        ->assertRedirect();

    $risk->refresh()->load('revisions');

    expect($risk->revisions)->toHaveCount(2)
        ->and($risk->score())->toBe(9)
        ->and($risk->isWorsening())->toBeTrue()
        ->and($risk->isEscalated())->toBeTrue()
        ->and($risk->revisions->last()->note)->toBe('Second export failed validation.')
        ->and(AuditLog::query()->where('action', 'risk.reassessed')->exists())->toBeTrue();
});

test('editing the wording alone appends no revision', function () {
    ['manager' => $manager, 'engagement' => $engagement] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);

    $risk->reassess(['title' => 'Migration source data still unreliable'], $manager);

    expect($risk->refresh()->revisions)->toHaveCount(1)
        ->and($risk->title)->toBe('Migration source data still unreliable');
});

test('a risk that was mitigated back down stops reading as worsening', function () {
    ['manager' => $manager, 'engagement' => $engagement] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload([
        'probability' => RiskRating::Low,
        'impact' => RiskRating::Medium,
    ]), $manager);

    $risk->reassess(['probability' => RiskRating::High, 'impact' => RiskRating::High], $manager);
    expect($risk->isWorsening())->toBeTrue();

    $risk->reassess(['probability' => RiskRating::Low, 'impact' => RiskRating::Medium], $manager);
    expect($risk->refresh()->isWorsening())->toBeFalse();
});

test('closing a risk stamps the moment and takes it off the live register', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'developer' => $developer] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload([
        'probability' => RiskRating::High,
        'impact' => RiskRating::High,
    ]), $manager);
    $risk->syncExposures([['rate_card_role_id' => $developer->id, 'days' => 10]], $manager);

    expect($engagement->riskExposure()['weighted']->amount)->toBe(405000)
        ->and($engagement->escalatedRisks())->toHaveCount(1);

    $risk->reassess(['status' => RiskStatus::Closed], $manager);

    expect($risk->refresh()->closed_at)->not->toBeNull()
        ->and($risk->isEscalated())->toBeFalse()
        ->and($engagement->riskExposure()['weighted']->amount)->toBe(0)
        ->and($engagement->escalatedRisks())->toBeEmpty();
});

test('the register orders worst first and surfaces escalated entries', function () {
    ['manager' => $manager, 'engagement' => $engagement] = riskSetup();

    $engagement->registerRisk(riskPayload([
        'title' => 'Minor styling drift',
        'probability' => RiskRating::Low,
        'impact' => RiskRating::Low,
    ]), $manager);
    $engagement->registerRisk(riskPayload([
        'title' => 'Migration data unreliable',
        'probability' => RiskRating::High,
        'impact' => RiskRating::High,
    ]), $manager);

    $this->actingAs($manager)
        ->get(route('engagements.risks.index', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engagements/risks')
            ->has('risks', 2)
            ->where('risks.0.title', 'Migration data unreliable')
            ->where('risks.0.escalated', true)
            ->where('summary.escalated', 1));
});

test('exposure figures are absent for members without rate card access', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'organization' => $organization, 'developer' => $developer] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);
    $risk->syncExposures([['rate_card_role_id' => $developer->id, 'days' => 10]], $manager);

    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->get(route('engagements.risks.index', $engagement))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('risks.0.title', 'Migration source data is unreliable')
            ->where('risks.0.exposure', null)
            ->where('risks.0.weightedExposure', null)
            ->where('summary.exposure', null)
            ->where('summary.weightedExposure', null));

    $this->actingAs($member)
        ->get(route('risks.show', $risk))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('risks/show')
            ->has('exposures', 0)
            ->where('can.priceExposure', false));
});

test('a member cannot raise or reassess risks and nobody can delete one', function () {
    ['manager' => $manager, 'engagement' => $engagement, 'organization' => $organization] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);
    $member = User::factory()->for($organization)->role(UserRole::Member)->create();

    $this->actingAs($member)
        ->post(route('engagements.risks.store', $engagement), riskPayload())
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('risks.update', $risk), riskPayload())
        ->assertForbidden();

    expect(fn () => $risk->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

test('risk revisions are frozen ratings that cannot be rewritten', function () {
    ['manager' => $manager, 'engagement' => $engagement] = riskSetup();

    $risk = $engagement->registerRisk(riskPayload(), $manager);
    $revision = RiskRevision::query()->where('risk_id', $risk->id)->sole();

    expect(fn () => $revision->update(['score' => 1]))
        ->toThrow(LogicException::class, 'cannot be updated')
        ->and(fn () => $revision->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

test('a risk raised without a published rate card cannot be priced', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $engagement = Engagement::factory()
        ->for($manager->organization)
        ->status(EngagementStatus::Active)
        ->create();

    $risk = $engagement->registerRisk(riskPayload(), $manager);

    expect($risk->rate_card_version_id)->toBeNull();

    $this->actingAs($manager)
        ->put(route('risks.exposure.update', $risk), [
            'lines' => [['rate_card_role_id' => RateCardRole::factory()->create()->id, 'days' => 3]],
        ])
        ->assertInvalid('lines');
});
