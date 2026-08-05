<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationAccount;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationAccount>
 */
class IntegrationAccountFactory extends Factory
{
    /**
     * Define the model's default state: a Jira account with encrypted
     * credentials, named uniquely so the per-organization name index never
     * collides across factory calls.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => IntegrationProvider::Jira,
            'name' => 'Jira — '.fake()->unique()->domainWord().'.atlassian.net',
            'base_url' => 'https://example.atlassian.net',
            'credentials' => ['email' => 'pm@example.com', 'api_token' => 'test-api-token'],
        ];
    }

    /**
     * A Linear account (API key, no site URL).
     */
    public function linear(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => IntegrationProvider::Linear,
            'name' => 'Linear — '.fake()->unique()->domainWord(),
            'base_url' => null,
            'credentials' => ['api_token' => 'lin_api_test_token'],
        ]);
    }
}
