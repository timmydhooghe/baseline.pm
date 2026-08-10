<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A published weekly report (FA-26): the record that a given week's report
 * went out, carrying the frozen twin snapshots it went out as. The internal
 * snapshot keeps the commercial position; the customer snapshot is built
 * without cost or margin — stripped structurally, never merely blanked.
 *
 * There is no draft state on this model: a draft is derived from evidence
 * every time it is read, so the row only exists once a report is published,
 * and from that moment it never changes. What was sent is what stays sent.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property Carbon $week_start
 * @property string|null $review_snapshot_id
 * @property string|null $customer_snapshot_id
 * @property CarbonImmutable $published_at
 * @property string|null $published_by
 * @property string|null $published_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read Snapshot|null $reviewSnapshot
 * @property-read Snapshot|null $customerSnapshot
 * @property-read User|null $publishedBy
 */
#[Fillable(['week_start', 'published_at', 'published_by', 'published_by_name'])]
class Report extends Model
{
    use BelongsToOrganization, HasUuids;

    protected static function booted(): void
    {
        static::updating(function (Report $report): void {
            /*
             * Publishing creates the row before its snapshots exist, so the
             * one permitted update is the publish flow pointing the fresh
             * record at the twins it just froze — and only while those
             * pointers are still empty.
             */
            $allowed = ['review_snapshot_id', 'customer_snapshot_id', 'updated_at'];

            if (array_diff(array_keys($report->getDirty()), $allowed) !== []) {
                throw new LogicException('A published report is immutable.');
            }

            if ($report->getRawOriginal('review_snapshot_id') !== null
                || $report->getRawOriginal('customer_snapshot_id') !== null) {
                throw new LogicException('A published report already carries its frozen snapshots.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Published reports are governance history and cannot be deleted.');
        });
    }

    /**
     * The human name of the week this report covers.
     */
    public function label(): string
    {
        return BurnWeek::labelFor($this->week_start);
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function reviewSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'review_snapshot_id');
    }

    /**
     * @return BelongsTo<Snapshot, $this>
     */
    public function customerSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'customer_snapshot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return MorphMany<Snapshot, $this>
     */
    public function snapshots(): MorphMany
    {
        return $this->morphMany(Snapshot::class, 'subject');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'published_at' => 'immutable_datetime',
        ];
    }
}
