<?php

namespace Database\Factories;

use App\Models\Baseline;
use App\Models\BaselineDocument;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BaselineDocument>
 */
class BaselineDocumentFactory extends Factory
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
            'baseline_id' => fn (array $attributes): string => Baseline::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'filename' => fake()->word().'-sow.pdf',
            'path' => 'baselines/'.fake()->uuid().'/contracts/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'uploaded_by' => null,
        ];
    }
}
