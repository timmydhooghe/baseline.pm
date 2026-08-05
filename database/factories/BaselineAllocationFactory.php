<?php

namespace Database\Factories;

use App\Models\Baseline;
use App\Models\BaselineAllocation;
use App\Models\Organization;
use App\Models\RateCardRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BaselineAllocation>
 */
class BaselineAllocationFactory extends Factory
{
    /**
     * Define the model's default state: a delivery-management line (no item)
     * priced against a same-organization rate card role.
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
            'baseline_item_id' => null,
            'rate_card_role_id' => fn (array $attributes): string => RateCardRole::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'days' => (string) fake()->randomFloat(2, 1, 20),
        ];
    }
}
