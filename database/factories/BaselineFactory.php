<?php

namespace Database\Factories;

use App\Enums\BaselineStatus;
use App\Enums\CommercialModel;
use App\Enums\EngagementStatus;
use App\Enums\ExecutionMode;
use App\Models\Baseline;
use App\Models\Engagement;
use App\Models\Organization;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Baseline>
 */
class BaselineFactory extends Factory
{
    /**
     * Define the model's default state: a draft v1 for an engagement that is
     * preparing its baseline. No rate card version is pinned by default —
     * tests wire one explicitly when pricing matters.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'engagement_id' => fn (array $attributes): string => Engagement::factory()
                ->status(EngagementStatus::PreparingBaseline)
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'version' => 1,
            'status' => BaselineStatus::Draft,
            'commercial_model' => CommercialModel::FixedPrice,
            'contract_value' => Money::fromCents(fake()->numberBetween(50, 500) * 100000),
            'start_date' => fake()->dateTimeBetween('+1 week', '+1 month'),
            'end_date' => fake()->dateTimeBetween('+2 months', '+6 months'),
            'execution_mode' => ExecutionMode::Standalone,
        ];
    }

    /**
     * Place the baseline directly in the given status.
     */
    public function status(BaselineStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
