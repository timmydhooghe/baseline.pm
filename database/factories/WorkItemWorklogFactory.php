<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\WorkItem;
use App\Models\WorkItemWorklog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkItemWorklog>
 */
class WorkItemWorklogFactory extends Factory
{
    /**
     * Define the model's default state: a recent half-day-ish entry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'work_item_id' => fn (array $attributes): string => WorkItem::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'author_name' => fake()->name(),
            'seconds' => fake()->numberBetween(1, 16) * 1800,
            'logged_on' => fake()->dateTimeBetween('-2 weeks', 'now'),
        ];
    }
}
