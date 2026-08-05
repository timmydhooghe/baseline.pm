<?php

namespace Database\Factories;

use App\Enums\ChangeRequestStatus;
use App\Enums\EngagementStatus;
use App\Models\ChangeRequest;
use App\Models\Engagement;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeRequest>
 */
class ChangeRequestFactory extends Factory
{
    /**
     * Define the model's default state: a draft on an active engagement.
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
            'status' => ChangeRequestStatus::Draft,
            'title' => fake()->unique()->sentence(4),
            'what' => fake()->paragraph(),
        ];
    }

    /**
     * Place the change request directly in the given status.
     */
    public function status(ChangeRequestStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
