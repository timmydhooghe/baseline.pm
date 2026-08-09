<?php

namespace Database\Factories;

use App\Enums\DecisionSource;
use App\Enums\DecisionStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Models\Decision;
use App\Models\Engagement;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Decision>
 */
class DecisionFactory extends Factory
{
    /**
     * Define the model's default state: an internal draft on an active
     * engagement, carrying the context but not yet the outcome — exactly
     * what a proposal looks like before somebody confirms it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'engagement_id' => fn (array $attributes): string => Engagement::factory()
                ->status(EngagementStatus::Active)
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'status' => DecisionStatus::Draft,
            'source' => DecisionSource::Manual,
            'title' => fake()->unique()->sentence(4),
            'context' => fake()->paragraph(),
            'visibility' => RecordVisibility::Internal,
        ];
    }

    /**
     * A record that is already on the ledger, outcome and date included.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DecisionStatus::Confirmed,
            'decision' => fake()->sentence(),
            'decided_on' => now()->subDays(3)->toDateString(),
        ]);
    }

    /**
     * A record the customer may see — and therefore acknowledge.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => RecordVisibility::Shared,
        ]);
    }
}
