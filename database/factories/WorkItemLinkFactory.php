<?php

namespace Database\Factories;

use App\Enums\BaselineItemType;
use App\Models\BaselineItem;
use App\Models\Organization;
use App\Models\WorkItem;
use App\Models\WorkItemLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkItemLink>
 */
class WorkItemLinkFactory extends Factory
{
    /**
     * Define the model's default state. Tests that care about the mapping
     * flow should go through WorkItem::linkTo() instead — it validates and
     * writes the audit entry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'work_item_id' => fn (array $attributes): string => WorkItem::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'baseline_item_id' => fn (array $attributes): string => BaselineItem::factory()
                ->type(BaselineItemType::Deliverable)
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
        ];
    }
}
