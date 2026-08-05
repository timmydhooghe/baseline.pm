<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to change the committed baseline (FA-11, FA-12). Today these are
 * drift-born drafts: triaging an unmapped work item as a potential change
 * pre-fills a draft from the item — what/why, effort seeded from the greater
 * of the provider estimate and logged time, and the earliest evidence that
 * execution already began. A non-null work_started_at is by construction
 * earlier than any approval, so it carries FA-9's contractual breach risk
 * flag. The lifecycle beyond draft belongs to change control (FA-11).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string|null $work_item_id
 * @property ChangeRequestStatus $status
 * @property string $title
 * @property string $what
 * @property string|null $why
 * @property string|null $origin
 * @property float|null $estimated_days
 * @property int $logged_seconds
 * @property CarbonImmutable|null $work_started_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read WorkItem|null $workItem
 * @property-read User|null $createdBy
 */
#[Fillable(['title', 'what', 'why', 'origin', 'estimated_days', 'logged_seconds', 'work_started_at', 'created_by'])]
class ChangeRequest extends Model
{
    /** @use HasFactory<ChangeRequestFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'logged_seconds' => 0,
    ];

    /**
     * Whether execution began before this change request was approved —
     * FA-9's contractual breach risk. A draft has no approval yet, so any
     * recorded work start flags it.
     */
    public function flagsContractualBreach(): bool
    {
        return $this->work_started_at !== null;
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

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
            'status' => ChangeRequestStatus::class,
            'estimated_days' => 'float',
            'logged_seconds' => 'integer',
            'work_started_at' => 'datetime',
        ];
    }
}
