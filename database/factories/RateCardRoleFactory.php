<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RateCardRole;
use App\Models\RateCardVersion;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateCardRole>
 */
class RateCardRoleFactory extends Factory
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
            'rate_card_version_id' => fn (array $attributes): string => RateCardVersion::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'name' => fake()->unique()->jobTitle(),
            'cost_per_day' => Money::fromCents(fake()->numberBetween(200, 700) * 100),
            'sell_per_day' => Money::fromCents(fake()->numberBetween(700, 1500) * 100),
            'position' => 0,
        ];
    }
}
