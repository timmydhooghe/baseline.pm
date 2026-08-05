<?php

namespace App\Models;

use App\Enums\BaselineItemType;
use App\Enums\ChangeRequestOrigin;
use App\Enums\EstimateUnit;
use App\Enums\IntegrationConnectionStatus;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemState;
use App\Enums\WorkItemTriageStatus;
use App\Jobs\PushWorkItemLink;
use App\Models\Concerns\BelongsToOrganization;
use App\ValueObjects\Money;
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
 * signal (FA-9): unmapped work is potential scope creep, surfaced in the
 * triage inbox until it is classified — existing scope, potential change,
 * operational or dismissed — with classifier and timestamp. Sync mirroring
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
 * @property WorkItemTriageStatus|null $triage_status
 * @property string|null $triage_note
 * @property string|null $triaged_by
 * @property CarbonImmutable|null $triaged_at
 * @property CarbonImmutable|null $external_updated_at
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read IntegrationConnection|null $integration
 * @property-read User|null $createdBy
 * @property-read User|null $triagedBy
 * @property-read Collection<int, WorkItemWorklog> $worklogs
 * @property-read WorkItemLink|null $link
 * @property-read ChangeRequest|null $changeRequest
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

        /*
         * afterCommit: the caller may hold an open bulk transaction, and the
         * queue is Redis — without it a rollback would leave already-queued
         * jobs commenting on mappings that never existed.
         */
        if ($this->external_id !== null && $this->integration?->status === IntegrationConnectionStatus::Connected) {
            PushWorkItemLink::dispatch($this, $deliverable)->afterCommit();
        }

        return $link;
    }

    /**
     * Remove the mapping; the item becomes unmapped work again and the
     * removal stays on the audit record. An existing-scope classification is
     * tied to the mapping it required, so unlinking clears it and the item
     * returns to the triage inbox — the audit trail keeps the history.
     */
    public function unlink(?User $actor = null): void
    {
        $link = $this->link;

        if ($link === null) {
            return;
        }

        DB::transaction(function () use ($link, $actor): void {
            $deliverable = $link->baselineItem;
            $link->delete();
            $this->setRelation('link', null);

            if ($this->triage_status === WorkItemTriageStatus::ExistingScope) {
                $this->triage_status = null;
                $this->triage_note = null;
                $this->triaged_by = null;
                $this->triaged_at = null;
                $this->save();
            }

            AuditLog::record('work_item.unlinked', $this, [
                'baseline_item_id' => $deliverable->id,
                'deliverable' => $deliverable->title,
                'work_item' => $this->external_key ?? $this->title,
                'unlinked_by' => $actor?->name,
            ]);
        });
    }

    /**
     * Classify this drift item out of the triage inbox (FA-9). Every
     * decision is recorded with classifier and timestamp and lands in the
     * audit log — dismissals included, so the call stays on record. Existing
     * scope must name the deliverable that absorbs the work; excluding work
     * as operational must log the explanation; a potential change drafts a
     * change request pre-filled from the item.
     */
    public function triage(
        WorkItemTriageStatus $status,
        User $actor,
        ?BaselineItem $deliverable = null,
        ?string $note = null,
    ): void {
        if ($this->link !== null) {
            throw ValidationException::withMessages([
                'classification' => __('This item is already mapped to a deliverable — it is not drift.'),
            ]);
        }

        if ($status === WorkItemTriageStatus::ExistingScope && $deliverable === null) {
            throw ValidationException::withMessages([
                'baseline_item_id' => __('Existing scope requires the deliverable that absorbs the work.'),
            ]);
        }

        if ($status === WorkItemTriageStatus::Operational && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages([
                'note' => __('Excluding work as operational requires an explanation — it stays on the record.'),
            ]);
        }

        DB::transaction(function () use ($status, $actor, $deliverable, $note): void {
            $changeRequest = null;

            if ($status === WorkItemTriageStatus::ExistingScope) {
                $this->linkTo($deliverable, $actor);
            }

            if ($status === WorkItemTriageStatus::PotentialChange) {
                $changeRequest = $this->changeRequest ?? $this->draftChangeRequest($actor);
            }

            $this->triage_status = $status;
            $this->triage_note = $note;
            $this->triaged_by = $actor->id;
            $this->triaged_at = now();
            $this->save();

            AuditLog::record('work_item.triaged', $this, [
                'classification' => $status->value,
                'work_item' => $this->external_key ?? $this->title,
                'triaged_by' => $actor->name,
                'note' => $note,
                'deliverable' => $deliverable?->title,
                'change_request_id' => $changeRequest?->id,
            ]);
        });
    }

    /**
     * The earliest evidence that execution began: the first worklog date,
     * or — when the state already moved past todo without any logged time —
     * the moment the item was first seen. Null means no work has demonstrably
     * started. This feeds FA-9's breach detection: work that starts before a
     * change request is approved is a contractual breach risk.
     */
    public function workStartedAt(): ?CarbonImmutable
    {
        $firstWorklog = $this->worklogs->sortBy('logged_on')->first();

        if ($firstWorklog !== null) {
            return $firstWorklog->logged_on->toImmutable();
        }

        if (in_array($this->state, [WorkItemState::InProgress, WorkItemState::Done], true)) {
            return $this->created_at?->toImmutable();
        }

        return null;
    }

    /**
     * The effort basis for pricing drift (FA-9): the greater of the estimate
     * converted to working days and the time actually logged — logged time
     * can outgrow the estimate, and unbilled risk should not shrink because
     * it did. Null when neither yields days (e.g. a points estimate with no
     * worklogs): unpriced beats invented.
     */
    public function effortDays(): ?float
    {
        $estimated = $this->estimate_value !== null
            ? $this->estimate_unit?->toDays($this->estimate_value)
            : null;
        $logged = $this->loggedSeconds() > 0
            ? $this->loggedSeconds() / 3600 / EstimateUnit::HOURS_PER_DAY
            : null;

        if ($estimated === null && $logged === null) {
            return null;
        }

        return max($estimated ?? 0.0, $logged ?? 0.0);
    }

    /**
     * Price this item's effort at a blended day rate (FA-9): internal cost
     * at cost/day, potential price at sell/day, whole cents. Null money when
     * the item has no priceable effort or there are no rates to derive from.
     *
     * @param  array{cost: Money, sell: Money}|null  $rates
     * @return array{days: float|null, cost: Money|null, price: Money|null}
     */
    public function priceEffort(?array $rates): array
    {
        $days = $this->effortDays();

        if ($days === null || $rates === null) {
            return ['days' => $days, 'cost' => null, 'price' => null];
        }

        return [
            'days' => $days,
            'cost' => Money::fromCents((int) round($days * $rates['cost']->amount)),
            'price' => Money::fromCents((int) round($days * $rates['sell']->amount)),
        ];
    }

    /**
     * Pre-fill a draft change request from this drift item (FA-9 → FA-12):
     * effort seeded from the greater of the provider estimate and logged
     * time, and the earliest evidence of started work snapshotted so the
     * contractual breach risk survives later syncs. One draft per item —
     * re-triaging reuses it.
     */
    protected function draftChangeRequest(User $actor): ChangeRequest
    {
        $origin = implode(' · ', array_filter([
            $this->external_key,
            $this->source->label(),
            $this->assignee_name,
        ]));

        $changeRequest = new ChangeRequest([
            'title' => __('Drift: :title', ['title' => $this->title]),
            'what' => __(':title (:origin) surfaced as unmapped drift work. Assess scope, affected deliverables and commercial terms.', [
                'title' => $this->title,
                'origin' => $origin,
            ]),
            'origin' => ChangeRequestOrigin::Drift,
            'estimated_days' => $this->effortDays(),
            'logged_seconds' => $this->loggedSeconds(),
            'work_started_at' => $this->workStartedAt(),
            'created_by' => $actor->id,
        ]);
        $changeRequest->organization_id = $this->organization_id;
        $changeRequest->engagement_id = $this->engagement_id;
        $changeRequest->work_item_id = $this->id;
        $changeRequest->save();

        $this->setRelation('changeRequest', $changeRequest);

        AuditLog::record('change_request.drafted', $changeRequest, [
            'work_item' => $this->external_key ?? $this->title,
            'title' => $changeRequest->title,
            'estimated_days' => $changeRequest->estimated_days,
            'work_started_at' => $changeRequest->work_started_at?->toIso8601String(),
        ]);

        return $changeRequest;
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
     * @return BelongsTo<User, $this>
     */
    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by');
    }

    /**
     * The change request drafted from this item, when it was triaged as a
     * potential change.
     *
     * @return HasOne<ChangeRequest, $this>
     */
    public function changeRequest(): HasOne
    {
        return $this->hasOne(ChangeRequest::class);
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
            'triage_status' => WorkItemTriageStatus::class,
            'triaged_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
