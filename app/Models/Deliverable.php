<?php

namespace App\Models;

use App\Enums\AcceptanceDecision;
use App\Enums\BaselineItemType;
use App\Enums\DeliverableConfidence;
use App\Enums\DeliverableStatus;
use App\Enums\EngagementStatus;
use App\Enums\RecordVisibility;
use App\Models\Concerns\BelongsToOrganization;
use App\Notifications\DeliverableSubmitted;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\DeliverableFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The living execution and acceptance record of a contracted deliverable
 * (FA-22, FA-23). The contractual definition — title, value, acceptance
 * criteria — lives on the immutable baseline item; this record carries what
 * moves: progress, confidence, forecast date, milestone assignment, evidence
 * and per-criterion evidence links. Submission freezes twin review snapshots
 * and the customer accepts, rejects or asks for clarification in the portal.
 * Accepted always means signed: the decision is recorded immutably and the
 * signed-off value accrues to the position rail. Approved change requests
 * repoint the record at the next baseline version's item row, so the record
 * and its history survive versioning.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $engagement_id
 * @property string $baseline_item_id
 * @property string|null $milestone_item_id
 * @property DeliverableStatus $status
 * @property int $progress
 * @property DeliverableConfidence $confidence
 * @property Carbon|null $forecast_date
 * @property list<array{evidence_id: string|null, visibility: string}>|null $criteria_state
 * @property string|null $review_snapshot_id
 * @property string|null $customer_snapshot_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $respond_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $accepted_at
 * @property Money|null $accepted_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Engagement $engagement
 * @property-read BaselineItem $baselineItem
 * @property-read BaselineItem|null $milestoneItem
 * @property-read Snapshot|null $reviewSnapshot
 * @property-read Snapshot|null $customerSnapshot
 * @property-read EloquentCollection<int, DeliverableVersion> $versions
 * @property-read EloquentCollection<int, DeliverableEvidence> $evidence
 * @property-read EloquentCollection<int, DeliverableResponse> $responses
 */
#[Fillable(['milestone_item_id', 'progress', 'confidence', 'forecast_date', 'criteria_state'])]
class Deliverable extends Model
{
    /** @use HasFactory<DeliverableFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'in_progress',
        'progress' => 0,
        'confidence' => 'medium',
    ];

    protected static function booted(): void
    {
        static::updating(function (Deliverable $deliverable): void {
            $original = DeliverableStatus::from((string) $deliverable->getRawOriginal('status'));

            /*
             * Repointing at the next baseline version's item row is the one
             * change a record must accept in any state — an approved change
             * request versions the ledger underneath every deliverable.
             */
            $versioning = ['baseline_item_id', 'milestone_item_id', 'updated_at'];

            if ($original === DeliverableStatus::Accepted) {
                if (array_diff(array_keys($deliverable->getDirty()), $versioning) !== []) {
                    throw new LogicException('An accepted deliverable is immutable — the signed acceptance is on record.');
                }
            }

            if ($original === DeliverableStatus::AwaitingAcceptance) {
                $allowed = [...$versioning, 'status', 'decided_at', 'accepted_at', 'accepted_value_cents'];

                if (array_diff(array_keys($deliverable->getDirty()), $allowed) !== []) {
                    throw new LogicException('A submitted deliverable is frozen while it awaits the customer decision.');
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Deliverable records are governance history and cannot be deleted.');
        });
    }

    /**
     * Create execution records for every deliverable item on the baseline
     * that does not have one yet: version 1 items at baseline approval, the
     * appended change-request deliverable at version minting. Idempotent —
     * existing records (repointed beforehand by the minting flow) are left
     * alone. The per-criterion state starts aligned with the item's criteria:
     * no evidence yet, shared by default.
     *
     * Scope that lands while the engagement's final acceptance sits with the
     * customer invalidates it: the frozen record they were asked to sign no
     * longer covers the engagement, so it is withdrawn and the engagement
     * reopens for delivery (FA-24).
     */
    public static function provisionForBaseline(Baseline $baseline): void
    {
        $items = $baseline->items()->where('type', BaselineItemType::Deliverable)->get();
        $provisioned = 0;

        foreach ($items as $item) {
            if (self::query()->withoutGlobalScopes()->where('baseline_item_id', $item->id)->exists()) {
                continue;
            }

            $deliverable = new self([
                'criteria_state' => array_map(
                    fn (): array => ['evidence_id' => null, 'visibility' => RecordVisibility::Shared->value],
                    $item->acceptance_criteria ?? [],
                ),
            ]);
            $deliverable->organization_id = $baseline->organization_id;
            $deliverable->engagement_id = $baseline->engagement_id;
            $deliverable->baseline_item_id = $item->id;
            $deliverable->save();

            $deliverable->versions()->create([
                'organization_id' => $baseline->organization_id,
                'baseline_item_id' => $item->id,
            ]);

            $provisioned++;
        }

        if ($provisioned > 0 && $baseline->engagement->status === EngagementStatus::AwaitingFinalAcceptance) {
            $baseline->engagement->transitionTo(EngagementStatus::Active);
        }
    }

    /**
     * Follow an approved change request onto the next baseline version:
     * repoint every execution record at its copied item row and extend the
     * version trail. The map comes from the minting flow, old item id to new.
     *
     * @param  array<string, string>  $itemMap
     */
    public static function repointToMintedItems(array $itemMap): void
    {
        $records = self::query()
            ->withoutGlobalScopes()
            ->whereIn('baseline_item_id', array_keys($itemMap))
            ->get();

        foreach ($records as $record) {
            $record->baseline_item_id = $itemMap[$record->baseline_item_id];

            if ($record->milestone_item_id !== null) {
                $record->milestone_item_id = $itemMap[$record->milestone_item_id] ?? null;
            }

            $record->save();

            $record->versions()->create([
                'organization_id' => $record->organization_id,
                'baseline_item_id' => $record->baseline_item_id,
            ]);
        }
    }

    /**
     * Submit the deliverable for customer acceptance (FA-23): freeze an
     * internal review snapshot plus a customer-facing one carrying shared
     * evidence only, stamp the respond-by deadline and notify every
     * stakeholder with approval rights. Acceptance is evidence-backed —
     * every criterion must link its evidence, and the customer must be able
     * to see at least one piece of it, before the review can start. The row
     * is locked and re-read so the snapshots freeze exactly the committed
     * record.
     */
    public function submitForAcceptance(DateTimeInterface|string $respondBy, ?User $submitter = null): void
    {
        $respondBy = CarbonImmutable::parse($respondBy)->endOfDay();

        DB::transaction(function () use ($respondBy, $submitter): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();
            $this->load(['baselineItem.baseline', 'baselineItem.owner', 'milestoneItem', 'engagement.customer.stakeholders', 'evidence']);

            if (! $this->status->canTransitionTo(DeliverableStatus::AwaitingAcceptance)) {
                throw new LogicException("A deliverable cannot be submitted from [{$this->status->value}].");
            }

            if ($respondBy->isPast()) {
                throw ValidationException::withMessages([
                    'respond_by' => __('The respond-by deadline must lie in the future.'),
                ]);
            }

            /*
             * With nobody who can sign, submission would freeze the record
             * against a decision that can never arrive — acceptance always
             * needs a customer approver.
             */
            if ($this->approvers()->isEmpty()) {
                throw ValidationException::withMessages([
                    'respond_by' => __('This customer has no stakeholder who can approve — invite an approver before submitting.'),
                ]);
            }

            $unevidenced = collect($this->criteria())
                ->filter(fn (array $criterion): bool => $criterion['evidence'] === null)
                ->pluck('criterion');

            if ($unevidenced->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'criteria' => __('Acceptance is evidence-backed — link evidence to every criterion first: :criteria', [
                        'criteria' => $unevidenced->implode(', '),
                    ]),
                ]);
            }

            /*
             * Criteria alone do not carry the guarantee: a change-request
             * deliverable is minted without any, and internal evidence never
             * reaches the portal. Either way the customer would be asked to
             * sign against an empty record, so require something they can see.
             */
            if ($this->evidence->every(fn (DeliverableEvidence $evidence): bool => ! $evidence->visibility->isShared())) {
                throw ValidationException::withMessages([
                    'criteria' => __('Acceptance is evidence-backed — share at least one piece of evidence with the customer before submitting.'),
                ]);
            }

            /*
             * The deadline is part of what the customer is asked to agree to,
             * so it is stamped before the payloads freeze around it.
             */
            $this->respond_by = $respondBy;

            $review = Snapshot::capture($this, $this->snapshotPayload(internal: true), $submitter);
            $customer = Snapshot::capture($this, $this->snapshotPayload(internal: false), $submitter);

            $this->status = DeliverableStatus::AwaitingAcceptance;
            $this->submitted_at = now();
            $this->decided_at = null;
            $this->review_snapshot_id = $review->id;
            $this->customer_snapshot_id = $customer->id;
            $this->save();

            AuditLog::record('deliverable.submitted', $this, [
                'deliverable' => $this->baselineItem->title,
                'respond_by' => $respondBy->toDateString(),
                'review_snapshot_id' => $review->id,
                'customer_snapshot_id' => $customer->id,
            ]);
        });

        foreach ($this->approvers() as $approver) {
            $approver->notify(new DeliverableSubmitted($this));
        }
    }

    /**
     * Pull a submitted deliverable back before the customer has decided —
     * the submission was premature, or the approvers who could sign it are
     * gone. The frozen snapshots stay on record and the record reopens for
     * editing; resubmission freezes fresh ones.
     */
    public function withdrawSubmission(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($this->status !== DeliverableStatus::AwaitingAcceptance) {
                throw ValidationException::withMessages([
                    'status' => __('Only a deliverable awaiting the customer decision can be withdrawn.'),
                ]);
            }

            $this->status = DeliverableStatus::InProgress;
            $this->save();

            AuditLog::record('deliverable.submission_withdrawn', $this, [
                'deliverable' => $this->baselineItem->title,
                'withdrawn_by' => $actor?->name,
            ]);
        });
    }

    /**
     * Record the customer's decision on the frozen review (FA-23). The
     * response is stored immutably against the snapshot it was made on;
     * callers pass the snapshot their page displayed, and the comparison
     * happens under the row lock so a signature can never land on a record
     * frozen after that page was opened. Acceptance is the signature — it
     * freezes the signed-off value onto the record and is terminal.
     * Rejection reopens the record for rework; a clarification request
     * reopens it without a verdict.
     */
    public function recordResponse(Stakeholder $stakeholder, AcceptanceDecision $decision, ?string $comment = null, ?string $displayedSnapshotId = null): DeliverableResponse
    {
        if (! $stakeholder->role->canApprove()) {
            throw ValidationException::withMessages([
                'decision' => __('Only stakeholders with approval rights can respond to a deliverable review.'),
            ]);
        }

        return DB::transaction(function () use ($stakeholder, $decision, $comment, $displayedSnapshotId): DeliverableResponse {
            self::query()->whereKey($this->id)->lockForUpdate()->first();

            $this->unsetRelations();
            $this->refresh();

            if ($stakeholder->customer_id !== $this->engagement->customer_id) {
                throw new LogicException('This stakeholder does not belong to the engagement customer.');
            }

            if ($this->status !== DeliverableStatus::AwaitingAcceptance || $this->customer_snapshot_id === null) {
                throw ValidationException::withMessages([
                    'decision' => __('This deliverable is no longer awaiting a decision.'),
                ]);
            }

            if ($displayedSnapshotId !== null && $displayedSnapshotId !== $this->customer_snapshot_id) {
                throw ValidationException::withMessages([
                    'decision' => __('This deliverable was revised after this page was opened — review the latest version from your most recent email.'),
                ]);
            }

            $response = new DeliverableResponse([
                'snapshot_id' => $this->customer_snapshot_id,
                'stakeholder_id' => $stakeholder->id,
                'stakeholder_name' => $stakeholder->name,
                'decision' => $decision,
                'comment' => $comment,
            ]);
            $response->organization_id = $this->organization_id;
            $response->deliverable_id = $this->id;
            $response->save();

            match ($decision) {
                AcceptanceDecision::Accepted => $this->applyAcceptance($stakeholder, $comment),
                AcceptanceDecision::Rejected => $this->applyRejection($stakeholder, $comment),
                AcceptanceDecision::ClarificationRequested => $this->applyClarification($stakeholder, $comment),
            };

            return $response;
        });
    }

    /**
     * The stakeholders who may sign off on this deliverable: the engagement
     * customer's contacts with approval rights.
     *
     * @return Collection<int, Stakeholder>
     */
    public function approvers(): Collection
    {
        return $this->engagement->customer->stakeholders
            ->filter(fn (Stakeholder $stakeholder): bool => $stakeholder->role->canApprove())
            ->values();
    }

    /**
     * The acceptance criteria as the record view sees them (FA-22): the
     * baseline item's contractual criterion and verification method zipped
     * with this record's execution state — the linked evidence item and its
     * visibility. State written against an older criteria list degrades
     * safely: missing entries read as unevidenced and shared.
     *
     * @return list<array{criterion: string, verification_method: string|null, evidence: DeliverableEvidence|null, visibility: RecordVisibility}>
     */
    public function criteria(): array
    {
        $state = $this->criteria_state ?? [];
        $evidence = $this->evidence->keyBy('id');
        $criteria = [];

        foreach ($this->baselineItem->acceptance_criteria ?? [] as $index => $criterion) {
            $entry = $state[$index] ?? ['evidence_id' => null, 'visibility' => RecordVisibility::Shared->value];

            $criteria[] = [
                'criterion' => $criterion['criterion'],
                'verification_method' => $criterion['verification_method'] ?? null,
                'evidence' => $evidence->get($entry['evidence_id']),
                'visibility' => RecordVisibility::tryFrom($entry['visibility']) ?? RecordVisibility::Shared,
            ];
        }

        return $criteria;
    }

    /**
     * The work items mapped to this deliverable, with their scope
     * classification (FA-22) — internal-only context for the record view.
     *
     * @return EloquentCollection<int, WorkItem>
     */
    public function linkedWork(): EloquentCollection
    {
        return WorkItem::query()
            ->whereHas('link', fn ($query) => $query->where('baseline_item_id', $this->baseline_item_id))
            ->with('link')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The frozen payload for a review snapshot. The customer variant carries
     * the contractual record and shared evidence only — confidence, internal
     * evidence and work-item classifications are built out structurally,
     * never merely blanked (FA-23, FA-27).
     *
     * @return array<string, mixed>
     */
    public function snapshotPayload(bool $internal): array
    {
        $item = $this->baselineItem;

        $criteria = array_map(function (array $criterion) use ($internal): array {
            $evidence = $criterion['evidence'];
            $shareEvidence = $evidence !== null
                && ($internal || ($criterion['visibility']->isShared() && $evidence->visibility->isShared()));

            $entry = [
                'criterion' => $criterion['criterion'],
                'verification_method' => $criterion['verification_method'],
                'evidence' => $shareEvidence ? [
                    'kind' => $evidence->kind->value,
                    'label' => $evidence->label,
                    'url' => $evidence->url,
                ] : null,
            ];

            if ($internal) {
                $entry['visibility'] = $criterion['visibility']->value;
            }

            return $entry;
        }, $this->criteria());

        $evidenceList = $this->evidence
            ->when(! $internal, fn (EloquentCollection $items) => $items->filter(
                fn (DeliverableEvidence $evidence): bool => $evidence->visibility->isShared(),
            ))
            ->map(fn (DeliverableEvidence $evidence): array => array_merge([
                'kind' => $evidence->kind->value,
                'label' => $evidence->label,
                'url' => $evidence->url,
            ], $internal ? ['visibility' => $evidence->visibility->value] : []))
            ->values()
            ->all();

        $payload = [
            'kind' => $internal ? 'internal_review' : 'customer_review',
            'deliverable' => [
                'id' => $this->id,
                'title' => $item->title,
                'description' => $item->description,
                'clause_reference' => $item->clause_reference,
                'baseline_version' => $item->baseline->version,
                'engagement' => [
                    'id' => $this->engagement->id,
                    'name' => $this->engagement->name,
                ],
                'customer' => [
                    'id' => $this->engagement->customer->id,
                    'name' => $this->engagement->customer->name,
                ],
            ],
            'value' => $item->value?->toArray(),
            'progress' => $this->progress,
            'forecast_date' => $this->forecast_date?->toDateString(),
            'milestone' => $this->milestoneItem === null ? null : [
                'id' => $this->milestoneItem->id,
                'title' => $this->milestoneItem->title,
                'baseline_date' => $this->milestoneItem->baseline_date?->toDateString(),
            ],
            'acceptance_criteria' => $criteria,
            'evidence' => $evidenceList,
            'respond_by' => $this->respond_by?->toDateString(),
        ];

        if (! $internal) {
            return $payload;
        }

        $payload['owner'] = $item->owner === null ? null : [
            'id' => $item->owner->id,
            'name' => $item->owner->name,
        ];
        $payload['confidence'] = $this->confidence->value;
        $payload['linked_work'] = $this->linkedWork()
            ->map(fn (WorkItem $workItem): array => [
                'id' => $workItem->id,
                'key' => $workItem->external_key,
                'title' => $workItem->title,
                'classification' => $workItem->triage_status?->value,
            ])
            ->values()
            ->all();

        return $payload;
    }

    protected function applyAcceptance(Stakeholder $stakeholder, ?string $comment): void
    {
        $payload = $this->customerSnapshot?->payload;
        $value = $payload['value'] ?? null;

        $this->status = DeliverableStatus::Accepted;
        $this->decided_at = now();
        $this->accepted_at = now();
        $this->accepted_value = is_array($value)
            ? Money::fromCents((int) $value['amount'], (string) $value['currency'])
            : null;
        $this->save();

        AuditLog::record('deliverable.accepted', $this, [
            'deliverable' => $this->baselineItem->title,
            'decided_by' => $stakeholder->name,
            'comment' => $comment,
            'value' => $this->accepted_value?->format(),
        ]);
    }

    protected function applyRejection(Stakeholder $stakeholder, ?string $comment): void
    {
        $this->status = DeliverableStatus::Rejected;
        $this->decided_at = now();
        $this->save();

        AuditLog::record('deliverable.rejected', $this, [
            'deliverable' => $this->baselineItem->title,
            'decided_by' => $stakeholder->name,
            'comment' => $comment,
        ]);
    }

    /**
     * A clarification request reopens the record for editing; the frozen
     * snapshots and the response stay on record, and resubmission freezes
     * fresh ones.
     */
    protected function applyClarification(Stakeholder $stakeholder, ?string $comment): void
    {
        $this->status = DeliverableStatus::InProgress;
        $this->save();

        AuditLog::record('deliverable.clarification_requested', $this, [
            'deliverable' => $this->baselineItem->title,
            'requested_by' => $stakeholder->name,
            'comment' => $comment,
        ]);
    }

    /**
     * @return BelongsTo<Engagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    /**
     * @return BelongsTo<BaselineItem, $this>
     */
    public function baselineItem(): BelongsTo
    {
        return $this->belongsTo(BaselineItem::class);
    }

    /**
     * @return BelongsTo<BaselineItem, $this>
     */
    public function milestoneItem(): BelongsTo
    {
        return $this->belongsTo(BaselineItem::class, 'milestone_item_id');
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
     * @return HasMany<DeliverableVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DeliverableVersion::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<DeliverableEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(DeliverableEvidence::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<DeliverableResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(DeliverableResponse::class)->latest('created_at');
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
            'status' => DeliverableStatus::class,
            'confidence' => DeliverableConfidence::class,
            'progress' => 'integer',
            'forecast_date' => 'date',
            'criteria_state' => 'array',
            'accepted_value' => Money::class,
            'submitted_at' => 'datetime',
            'respond_by' => 'datetime',
            'decided_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
