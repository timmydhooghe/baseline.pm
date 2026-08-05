<?php

use App\Enums\BaselineItemType;
use App\Enums\BaselineStatus;
use App\Enums\UserRole;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\User;

test('a deliverable is added with owner, value and acceptance criteria', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $owner = User::factory()->for($manager->organization)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.items.store', $baseline), [
            'type' => 'deliverable',
            'title' => 'Checkout flow',
            'description' => 'The full purchase funnel.',
            'clause_reference' => 'SOW §2.1',
            'owner_id' => $owner->id,
            'value' => '48000.50',
            'acceptance_criteria' => [
                ['criterion' => 'All payment methods pass UAT', 'verification_method' => 'UAT sign-off'],
                ['criterion' => 'Page loads under 2s', 'verification_method' => ''],
            ],
        ])
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    $item = BaselineItem::query()->sole();

    expect($item->type)->toBe(BaselineItemType::Deliverable)
        ->and($item->owner_id)->toBe($owner->id)
        ->and($item->value?->amount)->toBe(4800050)
        ->and($item->clause_reference)->toBe('SOW §2.1')
        ->and($item->acceptance_criteria)->toBe([
            ['criterion' => 'All payment methods pass UAT', 'verification_method' => 'UAT sign-off'],
            ['criterion' => 'Page loads under 2s', 'verification_method' => null],
        ])
        ->and($item->position)->toBe(1);
});

test('a milestone is added with baseline date and payment trigger', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.items.store', $baseline), [
            'type' => 'milestone',
            'title' => 'Go-live',
            'clause_reference' => 'SOW §4',
            'baseline_date' => '2026-11-30',
            'payment_trigger' => '40% on go-live',
        ]);

    $item = BaselineItem::query()->sole();

    expect($item->type)->toBe(BaselineItemType::Milestone)
        ->and($item->baseline_date?->toDateString())->toBe('2026-11-30')
        ->and($item->payment_trigger)->toBe('40% on go-live');
});

test('assumptions, exclusions and responsibilities trace to their clause', function (string $type) {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.items.store', $baseline), [
            'type' => $type,
            'title' => 'Customer provides API credentials',
            'clause_reference' => 'Annex B',
        ]);

    expect(BaselineItem::query()->sole()->type->value)->toBe($type);
})->with(['assumption', 'exclusion', 'responsibility']);

test('every item must trace to a contract clause', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();

    $this->actingAs($manager)
        ->post(route('baselines.items.store', $baseline), [
            'type' => 'assumption',
            'title' => 'Untraced assumption',
        ])
        ->assertInvalid(['clause_reference']);
});

test('the deliverable owner must belong to the organization', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();
    $outsider = User::factory()->create();

    $this->actingAs($manager)
        ->post(route('baselines.items.store', $baseline), [
            'type' => 'deliverable',
            'title' => 'Checkout flow',
            'clause_reference' => 'SOW §2.1',
            'owner_id' => $outsider->id,
        ])
        ->assertInvalid(['owner_id']);
});

test('positions count up within each item type', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();
    BaselineItem::factory()->for($manager->organization)->for($baseline)->create(['position' => 1]);

    $this->actingAs($manager)->post(route('baselines.items.store', $baseline), [
        'type' => 'deliverable', 'title' => 'Second deliverable', 'clause_reference' => 'SOW §2.2',
    ]);
    $this->actingAs($manager)->post(route('baselines.items.store', $baseline), [
        'type' => 'milestone', 'title' => 'First milestone', 'clause_reference' => 'SOW §4',
    ]);

    expect(BaselineItem::query()->where('title', 'Second deliverable')->sole()->position)->toBe(2)
        ->and(BaselineItem::query()->where('title', 'First milestone')->sole()->position)->toBe(1);
});

test('items can be updated while drafting', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();
    $item = BaselineItem::factory()->for($manager->organization)->for($baseline)->create();

    $this->actingAs($manager)
        ->patch(route('baselines.items.update', [$baseline, $item]), [
            'title' => 'Renamed deliverable',
            'clause_reference' => 'SOW §9',
            'value' => '12000',
        ])
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    expect($item->refresh()->title)->toBe('Renamed deliverable')
        ->and($item->value?->amount)->toBe(1200000);
});

test('items are removed while drafting', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();
    $item = BaselineItem::factory()->for($manager->organization)->for($baseline)->create();

    $this->actingAs($manager)
        ->delete(route('baselines.items.destroy', [$baseline, $item]))
        ->assertRedirect(route('engagements.baseline.show', $baseline->engagement_id));

    expect(BaselineItem::query()->count())->toBe(0);
});

test('an item is only reachable through its own baseline', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();
    $otherBaseline = Baseline::factory()->for($manager->organization)->create();
    $item = BaselineItem::factory()->for($manager->organization)->for($otherBaseline)->create();

    $this->actingAs($manager)
        ->patch(route('baselines.items.update', [$baseline, $item]), [
            'title' => 'Hijacked', 'clause_reference' => 'SOW §1',
        ])
        ->assertNotFound();
});

test('items cannot change through the builder once the baseline is submitted', function () {
    $manager = User::factory()->role(UserRole::DeliveryManager)->create();
    $baseline = Baseline::factory()->for($manager->organization)->create();
    $item = BaselineItem::factory()->for($manager->organization)->for($baseline)->create();
    $baseline->status = BaselineStatus::AwaitingApproval;
    $baseline->save();

    $this->actingAs($manager)
        ->post(route('baselines.items.store', $baseline), [
            'type' => 'assumption', 'title' => 'Late addition', 'clause_reference' => 'SOW §1',
        ])
        ->assertForbidden();

    $this->actingAs($manager)->delete(route('baselines.items.destroy', [$baseline, $item]))->assertForbidden();
});

test('items of a non-draft baseline refuse changes at the model level', function () {
    $baseline = Baseline::factory()->create();
    $item = BaselineItem::factory()->for($baseline->organization)->for($baseline)->create();
    $baseline->status = BaselineStatus::AwaitingApproval;
    $baseline->save();
    $item->refresh();

    expect(fn () => $item->update(['title' => 'Sneaky edit']))
        ->toThrow(LogicException::class, 'Baseline items can only change while the baseline is a draft.');

    expect(fn () => $item->delete())
        ->toThrow(LogicException::class, 'Baseline items can only change while the baseline is a draft.');
});
