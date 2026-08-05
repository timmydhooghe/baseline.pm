<?php

namespace Database\Factories;

use App\Enums\BaselineItemType;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Organization;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BaselineItem>
 */
class BaselineItemFactory extends Factory
{
    /**
     * Define the model's default state: a deliverable that still misses its
     * typed fields, the way items start out while drafting.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'baseline_id' => fn (array $attributes): string => Baseline::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'type' => BaselineItemType::Deliverable,
            'position' => 1,
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->paragraph(),
            'clause_reference' => 'SOW §'.fake()->numberBetween(1, 12),
        ];
    }

    public function type(BaselineItemType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    /**
     * A deliverable that passes every completeness check: owner, value and
     * verifiable acceptance criteria.
     */
    public function completeDeliverable(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BaselineItemType::Deliverable,
            'owner_id' => User::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'value' => Money::fromCents(fake()->numberBetween(10, 100) * 100000),
            'acceptance_criteria' => [
                ['criterion' => 'All flows pass UAT', 'verification_method' => 'UAT sign-off report'],
            ],
        ]);
    }

    /**
     * A milestone that passes every completeness check: baseline date and
     * payment trigger.
     */
    public function completeMilestone(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BaselineItemType::Milestone,
            'baseline_date' => fake()->dateTimeBetween('+1 month', '+3 months'),
            'payment_trigger' => '30% on acceptance',
        ]);
    }
}
