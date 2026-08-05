<?php

namespace Database\Factories;

use App\Enums\ChangeRequestDecision;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestResponse;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\Stakeholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeRequestResponse>
 */
class ChangeRequestResponseFactory extends Factory
{
    /**
     * Define the model's default state: an approval recorded against a
     * snapshot of the change request.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'change_request_id' => fn (array $attributes): string => ChangeRequest::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'snapshot_id' => function (array $attributes): string {
                $changeRequest = ChangeRequest::query()->findOrFail((string) $attributes['change_request_id']);

                return Snapshot::capture($changeRequest, ['kind' => 'customer_review'])->id;
            },
            'stakeholder_id' => fn (array $attributes): string => Stakeholder::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'stakeholder_name' => fake()->name(),
            'decision' => ChangeRequestDecision::Approved,
            'comment' => null,
        ];
    }
}
