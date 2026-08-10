<?php

namespace Database\Factories;

use App\Enums\BaselineDecision;
use App\Models\Baseline;
use App\Models\BaselineResponse;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BaselineResponse>
 */
class BaselineResponseFactory extends Factory
{
    /**
     * Define the model's default state: an approval recorded against a
     * snapshot of the baseline.
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
            'snapshot_id' => function (array $attributes): string {
                $baseline = Baseline::query()->findOrFail((string) $attributes['baseline_id']);

                return Snapshot::capture($baseline, ['kind' => 'customer_review'])->id;
            },
            'stakeholder_id' => fn (array $attributes): string => Stakeholder::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'stakeholder_name' => fake()->name(),
            'decision' => BaselineDecision::Approved,
            'comment' => null,
        ];
    }
}
