<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Enums\WorkItemSource;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    /**
     * Define the model's default state: an unreleased Jira version on an
     * active engagement.
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
            'source' => WorkItemSource::Jira,
            'external_id' => (string) fake()->unique()->numberBetween(10000, 99999),
            'name' => fake()->unique()->numerify('v#.#.0'),
            'released' => false,
        ];
    }

    /**
     * A version that shipped.
     */
    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'released' => true,
            'released_on' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}
