<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Models\Engagement;
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
            'provider' => IntegrationProvider::Jira,
            'status' => IntegrationConnectionStatus::Connected,
            'credentials' => ['email' => 'pm@example.com', 'api_token' => 'test-api-token'],
            'base_url' => 'https://example.atlassian.net',
            'external_project_key' => 'ENG',
            'connected_at' => now(),
        ];
    }

    /**
     * A connected Linear integration (API key, no site URL).
     */
    public function linear(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => IntegrationProvider::Linear,
            'credentials' => ['api_token' => 'lin_api_test_token'],
            'base_url' => null,
        ]);
    }

    /**
     * A disconnected integration: credentials wiped, history retained.
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IntegrationConnectionStatus::Disconnected,
            'credentials' => null,
            'disconnected_at' => now(),
        ]);
    }
}
