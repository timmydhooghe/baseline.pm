<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Models\Engagement;
use App\Models\IntegrationAccount;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationConnection>
 */
class IntegrationConnectionFactory extends Factory
{
    /**
     * Define the model's default state: a connected Jira integration on an
     * active engagement, syncing through an org-level Jira account.
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
            'integration_account_id' => fn (array $attributes): string => IntegrationAccount::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'provider' => IntegrationProvider::Jira,
            'status' => IntegrationConnectionStatus::Connected,
            'external_project_key' => 'ENG',
            'connected_at' => now(),
        ];
    }

    /**
     * A connected Linear integration syncing through a Linear account.
     */
    public function linear(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => IntegrationProvider::Linear,
            'integration_account_id' => fn (array $currentAttributes): string => IntegrationAccount::factory()
                ->linear()
                ->create(['organization_id' => $currentAttributes['organization_id']])
                ->id,
        ]);
    }

    /**
     * A disconnected integration: account link dropped, history retained.
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntegrationConnectionStatus::Disconnected,
            'integration_account_id' => null,
            'disconnected_at' => now(),
        ]);
    }
}
