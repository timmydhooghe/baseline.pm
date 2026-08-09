<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Enums\RiskRating;
use App\Enums\RiskStatus;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Risk>
 */
class RiskFactory extends Factory
{
    /**
     * Define the model's default state: an open medium × medium risk on an
     * active engagement. Flow tests raise risks through
     * Engagement::registerRisk() so the opening revision is frozen too;
     * this factory exists for policy and unit setups.
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
            'title' => fake()->unique()->sentence(4),
            'description' => fake()->paragraph(),
            'probability' => RiskRating::Medium,
            'impact' => RiskRating::Medium,
            'status' => RiskStatus::Open,
            'visibility' => RecordVisibility::Internal,
        ];
    }

    /**
     * Rate the risk directly — `rating(RiskRating::High, RiskRating::High)`
     * is the H×H entry that escalates.
     */
    public function rating(RiskRating $probability, RiskRating $impact): static
    {
        return $this->state(fn (array $attributes): array => [
            'probability' => $probability,
            'impact' => $impact,
        ]);
    }

    /**
     * Place the risk directly in the given status.
     */
    public function status(RiskStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}
