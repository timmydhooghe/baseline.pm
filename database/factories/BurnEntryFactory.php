<?php

namespace Database\Factories;

use App\Enums\BurnSource;
use App\Models\BurnEntry;
use App\Models\BurnWeek;
use App\Models\Organization;
use App\Models\RateCardRole;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BurnEntry>
 */
class BurnEntryFactory extends Factory
{
    /**
     * Define the model's default state: a manual line of five days against a
     * same-organization rate card role, priced at that role's cost rate the
     * way recording does it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'burn_week_id' => fn (array $attributes): string => BurnWeek::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'rate_card_role_id' => fn (array $attributes): string => RateCardRole::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'role_name' => fn (array $attributes): string => $this->role($attributes)->name,
            'user_id' => null,
            'person_name' => fake()->name(),
            'days' => '5.00',
            'source' => BurnSource::Manual,
            'cost_per_day' => fn (array $attributes): Money => $this->role($attributes)->cost_per_day,
            'cost' => fn (array $attributes): Money => $this->role($attributes)->cost_per_day->multiply(5),
        ];
    }

    /**
     * The role the line is priced against, resolved from the id the state
     * closures have already settled on.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function role(array $attributes): RateCardRole
    {
        return RateCardRole::query()
            ->withoutGlobalScopes()
            ->whereKey($attributes['rate_card_role_id'])
            ->firstOrFail();
    }
}
