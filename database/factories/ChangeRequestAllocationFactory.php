<?php

namespace Database\Factories;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestAllocation;
use App\Models\Organization;
use App\Models\RateCardRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeRequestAllocation>
 */
class ChangeRequestAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'change_request_id' => fn (array $attributes): string => ChangeRequest::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'rate_card_role_id' => fn (array $attributes): string => RateCardRole::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'days' => (string) fake()->numberBetween(1, 20),
        ];
    }
}
