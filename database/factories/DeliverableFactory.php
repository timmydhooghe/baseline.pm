<?php

namespace Database\Factories;

use App\Enums\DeliverableConfidence;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Models\Baseline;
use App\Models\BaselineItem;
use App\Models\Deliverable;
use App\Models\Engagement;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deliverable>
 */
class DeliverableFactory extends Factory
{
    /**
     * Define the model's default state: an in-progress record for a complete
     * deliverable item on a draft baseline of an active engagement. Flow
     * tests provision records through Deliverable::provisionForBaseline()
     * instead — this factory exists for policy and unit setups.
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
            'baseline_item_id' => function (array $attributes): string {
                $baseline = Baseline::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'engagement_id' => $attributes['engagement_id'],
                ]);

                return BaselineItem::factory()
                    ->completeDeliverable()
                    ->create([
                        'organization_id' => $attributes['organization_id'],
                        'baseline_id' => $baseline->id,
                    ])
                    ->id;
            },
            'status' => DeliverableStatus::InProgress,
            'progress' => 0,
            'confidence' => DeliverableConfidence::Medium,
        ];
    }

    /**
     * Place the deliverable directly in the given status.
     */
    public function status(DeliverableStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
