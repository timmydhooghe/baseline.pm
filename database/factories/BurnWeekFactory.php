<?php

namespace Database\Factories;

use App\Enums\EngagementStatus;
use App\Models\BurnWeek;
use App\Models\Engagement;
use App\Models\Organization;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BurnWeek>
 */
class BurnWeekFactory extends Factory
{
    /**
     * Define the model's default state: last week, recorded, carrying no
     * lines. Flow tests record weeks through Engagement::recordBurnWeek() so
     * the lines are priced and the snapshot frozen the way the product does
     * it; this factory exists for policy and read-path setups.
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
            'week_start' => BurnWeek::startOfWeekFor(now()->subWeek()),
            'cost' => Money::zero(),
            'recorded_at' => now(),
        ];
    }

    /**
     * Record the week covering the given date.
     */
    public function forWeekOf(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'week_start' => BurnWeek::startOfWeekFor($date),
        ]);
    }
}
