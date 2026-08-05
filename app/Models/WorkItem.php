<?php

namespace App\Models;

use App\Enums\BaselineItemType;
use App\Enums\EstimateUnit;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemState;
use App\Jobs\PushWorkItemLink;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\WorkItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A unit of execution work on an engagement (FA-7, FA-8): an issue imported
 * from Jira or Linear, or a manual item recorded in standalone mode. Synced
 * items mirror the provider and are refreshed by sync runs; manual items are
 * edited by hand. Whether the item is mapped to a deliverable is the drift
 * signal (FA-9): unmapped work is potential scope creep. Sync mirroring
 * writes no audit entries — the governance moments (mapping, manual
 * recording) are audited explicitly.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string|null $integration_connection_id
 * @property WorkItemSource $source
 * @property string|null $external_id
 * @property string|null $external_key
 * @property string|null $external_url
 * @property string $title
 * @property string|null $external_status
 * @property WorkItemState $state
 * @property string|null $type
 * @property string|null $assignee_name
 * @property float|null $estimate_value
 * @property EstimateUnit|null $estimate_unit
 * @property CarbonImmutable|null $external_updated_at
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read IntegrationConnection|null $integration
 * @property-read User|null $createdBy
 * @property-read Collection<int, WorkItemWorklog> $worklogs
 * @property-read WorkItemLink|null $link
 */
#[Fillable(['source', 'external_id', 'external_key', 'external_url', 'title', 'external_status', 'state', 'type', 'assignee_name', 'estimate_value', 'estimate_unit', 'external_updated_at', 'last_synced_at', 'created_by'])]
class WorkItem extends Model
{
    /** @use HasFactory<WorkItemFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * Map this work item to the deliverable it belongs to (FA-8). One
     * mapping per item — relinking replaces the previous one, and the audit
     * log records who linked what, when. For synced items the mapping is
     * pushed back to the provider as an issue comment: the outbound half of
     * the two-way sync.
     */
    public function linkTo(BaselineItem $deliverable, ?User $actor = null): WorkItemLink
    {
        if ($deliverable->type !== BaselineItemType::Deliverable) {
            throw ValidationException::withMessages([
                'baseline_item_id' => __('Work can only be mapped to a deliverable.'),
            ]);
        }

        if ($deliverable->baseline->engagement_id !== $this->engagement_id) {
            throw ValidationException::withMessages([
                'baseline_item_id' => __('That deliverable belongs to another engagement.'),
            ]);
        }

        $link = DB::transaction(function () use ($deliverable, $actor): WorkItemLink {
            $this->link?->delete();

            $link = new WorkItemLink(['baseline_item_id' => $deliverable->id, 'linked_by' => $actor?->id]);
            $link->organization_id = $this->organization_id;
            $link->work_item_id = $this->id;
            $link->save();

            AuditLog::record('work_item.linked', $this, [
                'baseline_item_id' => $deliverable->id,
                'deliverable' => $deliverable->title,
                'work_item' => $this->external_key ?? $this->title,
                'linked_by' => $actor?->name,
            ]);

            return $link;
        });

        $this->setRelation('link', $link);

        if ($this->external_id !== null && $this->integration?->status === IntegrationConnectionStatus::Connected) {
            PushWorkItemLink::dispatch($this, $deliverable);
        }

        return $link;
    }

    /**
     * Remove the mapping; the item becomes unmapped work again and the
     * removal stays on the audit record.
     */
    public function unlink(?User $actor = null): void
    {
        $link = $this->link;

        if ($link === null) {
            return;
        }

        $deliverable = $link->baselineItem;
        $link->delete();
        $this->setRelation('link', null);

        AuditLog::record('work_item.unlinked', $this, [
            'baseline_item_id' => $deliverable->id,
            'deliverable' => $deliverable->title,
            'work_item' => $this->external_key ?? $this->title,
            'unlinked_by' => $actor?->name,
        ]);
    }

    /**
     * Record time by hand on a manual work item. Synced items get their
     * worklogs from the provider — manual entries there would double-count.
     */
    public function addManualWorklog(float $hours, string $loggedOn, ?User $author = null): WorkItemWorklog
    {
        if ($this->source !== WorkItemSource::Manual) {
            throw ValidationException::withMessages([
                'hours' => __('Time on synced items comes from the provider; log it there instead.'),
            ]);
        }

        $worklog = new WorkItemWorklog([
            'author_name' => $author->name ?? __('Unknown'),
            'seconds' => (int) round($hours * 3600),
            'logged_on' => $loggedOn,
            'created_by' => $author?->id,
        ]);
        $worklog->organization_id = $this->organization_id;
        $worklog->work_item_id = $this->id;
        $worklog->save();

        AuditLog::record('work_item.worklog_recorded', $this, [
            'hours' => $hours,
            'logged_on' => $loggedOn,
        ]);

        return $worklog;
    }

    /**
     * Total time logged against this item, in seconds.
     */
    public function loggedSeconds(): int
    {
        return (int) $this->worklogs->sum('seconds');
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * Named integration, not connection — Eloquent already owns a
     * $connection property (the database connection name).
     *
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WorkItemWorklog, $this>
     */
    public function worklogs(): HasMany
    {
        return $this->hasMany(WorkItemWorklog::class);
    }

    /**
     * @return HasOne<WorkItemLink, $this>
     */
    public function link(): HasOne
    {
        return $this->hasOne(WorkItemLink::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => WorkItemSource::class,
            'state' => WorkItemState::class,
            'estimate_value' => 'float',
            'estimate_unit' => EstimateUnit::class,
            'external_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
