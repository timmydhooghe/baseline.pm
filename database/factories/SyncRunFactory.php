<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state: a completed, successful pass.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'integration_connection_id' => fn (array $attributes): string => IntegrationConnection::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'status' => SyncRunStatus::Succeeded,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'counts' => ['work_items' => 3, 'worklogs' => 2, 'releases' => 1],
        ];
    }

    /**
     * A pass that broke against the provider.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Failed,
            'counts' => null,
            'error' => 'HTTP request returned status code 401',
        ]);
    }
}
