<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\WorkItemWorklogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time logged against a work item (FA-7): synced from the provider (Jira
 * worklogs) or recorded manually in standalone mode. The burn flow (FA-16)
 * reads these as its time-tracking source.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_item_id
 * @property string|null $external_id
 * @property string $author_name
 * @property int $seconds
 * @property Carbon $logged_on
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read WorkItem $workItem
 * @property-read User|null $createdBy
 */
#[Fillable(['external_id', 'author_name', 'seconds', 'logged_on', 'created_by'])]
class WorkItemWorklog extends Model
{
    /** @use HasFactory<WorkItemWorklogFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * @return BelongsTo<WorkItem, $this>
     */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seconds' => 'integer',
            'logged_on' => 'date',
        ];
    }
}
