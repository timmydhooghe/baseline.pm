<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RateCardVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateCardVersion>
 */
class RateCardVersionFactory extends Factory
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
            'version' => 1,
            'created_by' => null,
        ];
    }

    /**
     * Publish the rate card at the given version number.
     */
    public function version(int $version): static
    {
        return $this->state(fn (array $attributes) => [
            'version' => $version,
        ]);
    }
}
