<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Enums\EstimateUnit;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemState;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkItem>
 */
class WorkItemFactory extends Factory
{
    /**
     * Define the model's default state: a manual (standalone-mode) item on
     * an active engagement. Synced items add their connection via
     * `->for($connection, 'integration')` plus the jira()/linear() state.
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
            'source' => WorkItemSource::Manual,
            'title' => fake()->unique()->sentence(4),
            'state' => WorkItemState::Todo,
        ];
    }

    /**
     * An item imported from Jira.
     */
    public function jira(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => WorkItemSource::Jira,
            'external_id' => (string) fake()->unique()->numberBetween(10000, 99999),
            'external_key' => 'ENG-'.fake()->unique()->numberBetween(1, 9999),
            'external_status' => 'In Progress',
            'state' => WorkItemState::InProgress,
            'estimate_value' => 8 * 3600,
            'estimate_unit' => EstimateUnit::Seconds,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * An item imported from Linear.
     */
    public function linear(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => WorkItemSource::Linear,
            'external_id' => fake()->unique()->uuid(),
            'external_key' => 'ENG-'.fake()->unique()->numberBetween(1, 9999),
            'external_status' => 'In Progress',
            'state' => WorkItemState::InProgress,
            'estimate_value' => 3,
            'estimate_unit' => EstimateUnit::Points,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Place the work item directly in the given normalized workflow state.
     */
    public function inState(WorkItemState $state): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => $state,
        ]);
    }
}
