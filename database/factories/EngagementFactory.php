<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Engagement>
 */
class EngagementFactory extends Factory
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
            'customer_id' => fn (array $attributes): string => Customer::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'name' => fake()->unique()->sentence(3),
            'status' => EngagementStatus::Draft,
        ];
    }

    /**
     * Place the engagement directly in the given lifecycle status.
     */
    public function status(EngagementStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
