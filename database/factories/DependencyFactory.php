<?php

namespace Database\Factories;

use App\Enums\DependencyParty;
use App\Enums\DependencyStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Models\Dependency;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dependency>
 */
class DependencyFactory extends Factory
{
    /**
     * Define the model's default state: an internally owed item due in a
     * week, owned by a named colleague — the register refuses entries
     * nobody owns.
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
            'description' => fake()->sentence(),
            'party' => DependencyParty::Internal,
            'responsible_user_id' => fn (array $attributes): string => User::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'required_on' => now()->addWeek()->toDateString(),
            'status' => DependencyStatus::Pending,
            'visibility' => RecordVisibility::Shared,
        ];
    }

    /**
     * An item the customer owes, owned by one of their stakeholders — the
     * entries that reach the portal action list.
     */
    public function owedByCustomer(?Stakeholder $stakeholder = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'party' => DependencyParty::Customer,
            'responsible_user_id' => null,
            'responsible_stakeholder_id' => $stakeholder instanceof Stakeholder
                ? $stakeholder->id
                : Stakeholder::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'customer_id' => Engagement::query()
                        ->withoutGlobalScopes()
                        ->whereKey($attributes['engagement_id'])
                        ->value('customer_id'),
                ])->id,
        ]);
    }

    /**
     * An item whose required date has already passed.
     */
    public function late(int $days = 5): static
    {
        return $this->state(fn (array $attributes): array => [
            'required_on' => now()->subDays($days)->toDateString(),
        ]);
    }
}
