<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\WorkItemLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The work mapping (FA-8): which deliverable a work item belongs to, who
 * linked it and when. Created and removed through WorkItem::linkTo() /
 * unlink(), which write the audit entries.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_item_id
 * @property string $baseline_item_id
 * @property string|null $linked_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read WorkItem $workItem
 * @property-read BaselineItem $baselineItem
 * @property-read User|null $linkedBy
 */
#[Fillable(['baseline_item_id', 'linked_by'])]
class WorkItemLink extends Model
{
    /** @use HasFactory<WorkItemLinkFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * @return BelongsTo<WorkItem, $this>
     */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /**
     * @return BelongsTo<BaselineItem, $this>
     */
    public function baselineItem(): BelongsTo
    {
        return $this->belongsTo(BaselineItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
